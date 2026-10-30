<?php

declare( strict_types=1 );

namespace Inc\Services\Profile\Learner;

use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\WorkDTO;
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
			$aggregates       = array();
			foreach ( $this->submissions->listByStudentAndGroupLesson( $personId, $row->progressId() ) as $sub ) {
				$submittedWorkIds[ $sub->workId ] = true;
				if ( null === $sub->taskId ) {
					$aggregates[ $sub->workId ] = $sub;
				}
			}

			// Базовый URL плеера уже посчитан в lessonMap (для уроков с контентом).
			$baseUrl = (string) ( $ctx->lessonMap[ $glid ]['player_url'] ?? '' );

			foreach ( $this->worksResolver->resolve( $row ) as $work ) {
				// ДЗ без явного дедлайна — к началу следующего занятия.
				$due = $this->homeworkDeadlines->deadlineFor( $row, $work, $next[ $glid ] ?? null );
				if ( null === $due ) {
					continue;
				}

				// Сданная работа остаётся в дедлайнах, пока в ней есть нерешённые задания, есть попытки и срок не вышел.
				$unsolved = null;
				if ( isset( $submittedWorkIds[ $work->id ] ) ) {
					$unsolved = $this->unsolvedOf( $work, $aggregates[ $work->id ] ?? null );
					if ( null === $unsolved || $due < $ctx->now ) {
						continue;
					}
				}

				// Bug 2: ссылка «сразу к нужной работе» — плеер урока + ?step=<ключ шага>.
				$workUrl = $this->workLink->forRow( $row, $work->id, $baseUrl );
				if ( null !== $unsolved && '' !== $workUrl ) {
					// Плеер откроет работу на первом нерешённом задании.
					$workUrl = (string) add_query_arg( array( 'task' => $unsolved['first_task_id'] ), $workUrl );
				}

				$deadlines[] = array(
					'due_at'     => $due,
					'topic'      => $work->title,
					'group_name' => $ctx->lessonMap[ $glid ]['group_name'],
					'overdue'    => $due < $ctx->now,
					'player_url' => $workUrl,
				) + ( null !== $unsolved ? array(
					'remaining'     => $unsolved['remaining'],
					'total'         => $unsolved['total'],
					'first_task_id' => $unsolved['first_task_id'],
				) : array() );
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

	/**
	 * Нерешённые задания сданной работы: неверный ответ или ответа нет; верные и ждущие ручной проверки — решённые.
	 * `null` — исправлять нечего (всё решено) или попытки закончились, либо сдачи нет.
	 *
	 * @return array{remaining: int, total: int, first_task_id: int}|null
	 */
	private function unsolvedOf( WorkDTO $work, ?SubmissionDTO $aggregate ): ?array {
		if ( null === $aggregate || ( $work->maxAttempts > 0 && $aggregate->attemptCount >= $work->maxAttempts ) || array() === $work->itemIds ) {
			return null;
		}

		$decoded  = json_decode( (string) $aggregate->answerText, true );
		$verdicts = is_array( $decoded ) ? $decoded : array();
		$unsolved = array_values( array_filter(
			array_map( 'intval', $work->itemIds ),
			static fn( int $taskId ): bool => ! in_array( $verdicts[ $taskId ]['verdict'] ?? '', array( 'correct', 'pending' ), true )
		) );

		return array() === $unsolved ? null : array(
			'remaining'     => count( $unsolved ),
			'total'         => count( $work->itemIds ),
			'first_task_id' => $unsolved[0],
		);
	}
}
