<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\DTO\Profile\LearnerContextDTO;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\EffectiveWorksResolver;
use Inc\Services\Course\HomeworkDeadlineService;
use Inc\Services\Course\LessonWorkLink;

/**
 * Class LearnerScheduleSection
 *
 * Секция кабинета ученика: ближайшие занятия и дедлайны работ.
 *
 * Выделена из LearnerService (Т14.3). Дип-линк `?step=` собирает {@see LessonWorkLink}.
 *
 * @package Inc\Services\Profile\Learner
 */
class LearnerScheduleSection {

	public function __construct(
		private readonly SubmissionRepository    $submissions,
		private readonly EffectiveWorksResolver  $worksResolver,
		private readonly LessonWorkLink          $workLink,
		private readonly HomeworkDeadlineService $homeworkDeadlines,
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
			// Идущее сейчас занятие остаётся в списке: с него ученик подключается к трансляции.
			if ( $lesson['scheduled_at'] && ( $lesson['scheduled_at'] >= $ctx->now || ! empty( $lesson['live'] ) ) ) {
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
		$next      = $this->homeworkDeadlines->nextLessonStarts( $ctx->rawRows );

		foreach ( $ctx->rawRows as $glid => $row ) {
			$submittedWorkIds = array();
			foreach ( $this->submissions->listByStudentAndGroupLesson( $personId, $row->progressId() ) as $sub ) {
				$submittedWorkIds[ $sub->workId ] = true;
			}

			// Базовый URL плеера уже посчитан в lessonMap (для уроков с контентом).
			$baseUrl = (string) ( $ctx->lessonMap[ $glid ]['player_url'] ?? '' );

			foreach ( $this->worksResolver->resolve( $row ) as $work ) {
				if ( isset( $submittedWorkIds[ $work->id ] ) ) {
					continue;
				}

				// ДЗ без явного дедлайна — к началу следующего занятия.
				$due = $this->homeworkDeadlines->deadlineFor( $row, $work, $next[ $glid ] ?? null );
				if ( null === $due ) {
					continue;
				}

				// Bug 2: ссылка «сразу к нужной работе» — плеер урока + ?step=<ключ шага>.
				$workUrl = $this->workLink->forRow( $row, $work->id, $baseUrl );

				$deadlines[] = array(
					'due_at'     => $due,
					'topic'      => $work->title,
					'group_name' => $ctx->lessonMap[ $glid ]['group_name'],
					'overdue'    => $due < $ctx->now,
					'player_url' => $workUrl,
				);
			}
		}

		// Сначала предстоящие (ближайший сверху), за ними просроченные (свежий сверху):
		// иначе давние несданные ДЗ — особенно у пришедшего в середине курса —
		// вытеснили бы из топа то, что ещё можно успеть.
		usort(
			$deadlines,
			static fn( $a, $b ) => ( $a['overdue'] <=> $b['overdue'] )
				?: ( $a['overdue'] ? strcmp( $b['due_at'], $a['due_at'] ) : strcmp( $a['due_at'], $b['due_at'] ) )
		);

		return $deadlines;
	}
}
