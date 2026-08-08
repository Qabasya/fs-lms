<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;

/**
 * Class LearnerScheduleSection
 *
 * Секция кабинета ученика: ближайшие занятия и дедлайны работ.
 *
 * Выделена из LearnerService (Т14.3). Дип-линк `?step=` дублируется в
 * NotificationService — общая точка на будущее, в распиле не дедуплицируется.
 *
 * @package Inc\Services\Profile\Learner
 */
class LearnerScheduleSection {

	public function __construct(
		private readonly SubmissionRepository   $submissions,
		private readonly EffectiveWorksResolver $worksResolver,
		private readonly LessonManager          $lessons,
	) {}

	/**
	 * Ближайшие занятия по возрастанию даты.
	 *
	 * @param LearnerContextDTO $ctx Контекст сборки
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function upcoming( LearnerContextDTO $ctx ): array {
		$upcoming = array();
		foreach ( $ctx->allLessons as $lesson ) {
			if ( $lesson['scheduled_at'] && $lesson['scheduled_at'] >= $ctx->now ) {
				$upcoming[] = $lesson;
			}
		}

		usort( $upcoming, static fn( $a, $b ) => strcmp( (string) $a['scheduled_at'], (string) $b['scheduled_at'] ) );

		return $upcoming;
	}

	/**
	 * Дедлайны работ (T12.2, D13): per-work дедлайн, иначе legacy `homeworkDueAt` занятия.
	 *
	 * Прошедшие НЕ скрываем — помечаем `overdue` (решать всё равно можно, hard cutoff нет).
	 * Уже сданные работы не напоминаем.
	 *
	 * @param LearnerContextDTO $ctx      Контекст сборки
	 * @param int               $personId Физлицо ученика
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function deadlines( LearnerContextDTO $ctx, int $personId ): array {
		$deadlines = array();

		foreach ( $ctx->rawRows as $glid => $row ) {
			$submittedWorkIds = array();
			foreach ( $this->submissions->listByStudentAndGroupLesson( $personId, $glid ) as $sub ) {
				$submittedWorkIds[ $sub->workId ] = true;
			}

			// Урок строки — для deep-link к конкретной работе (ключ шага); базовый
			// URL плеера уже посчитан в lessonMap (для уроков с контентом).
			$lesson  = $row->lessonId ? $this->lessons->get( $row->lessonId ) : null;
			$baseUrl = (string) ( $ctx->lessonMap[ $glid ]['player_url'] ?? '' );

			foreach ( $this->worksResolver->resolve( $row ) as $work ) {
				if ( isset( $submittedWorkIds[ $work->id ] ) ) {
					continue;
				}

				$due = $row->deadlineForWork( $work->id );
				if ( null === $due ) {
					continue;
				}

				// Bug 2: ссылка «сразу к нужной работе» — плеер урока + ?step=<ключ шага>.
				$stepKey = $lesson?->stepKeyForWork( $work->id );
				$workUrl = ( '' !== $baseUrl && $stepKey )
					? (string) add_query_arg( 'step', $stepKey, $baseUrl )
					: $baseUrl;

				$deadlines[] = array(
					'due_at'     => $due,
					'topic'      => $work->title,
					'group_name' => $ctx->lessonMap[ $glid ]['group_name'],
					'overdue'    => $due < $ctx->now,
					'player_url' => $workUrl,
				);
			}
		}

		usort( $deadlines, static fn( $a, $b ) => strcmp( $a['due_at'], $b['due_at'] ) );

		return $deadlines;
	}
}
