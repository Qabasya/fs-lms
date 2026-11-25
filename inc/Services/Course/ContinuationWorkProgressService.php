<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\LessonStatus;
use Inc\Enums\Course\LessonVisibility;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Repositories\WPDBRepositories\WorkTaskCheckRepository;

/** Разбивка одной работы темы по фактическому времени ответов ученика. Оценка у работы одна. */
class ContinuationWorkProgressService {

	/** Даты программы читаются один раз при сборке нескольких шагов плеера. */
	private array $groupDates = array();

	public function __construct(
		private readonly GroupLessonRepository   $lessons,
		private readonly WorkManager             $works,
		private readonly WorkMarksService        $marks,
		private readonly TaskAttemptRepository    $attempts,
		private readonly WorkTaskCheckRepository $checks,
		private readonly LessonVisibilityService $visibility,
		private readonly ClockInterface          $clock,
	) {}

	/** @param GroupLessonDTO[] $rows @return array<int, array<int, string>> Исходная строка → начавшиеся части темы по времени. */
	public function lessonDates( array $rows ): array {
		$families = array();
		foreach ( $rows as $row ) {
			if ( null !== $row->scheduledAt && ! LessonStatus::fromValueOrDefault( $row->status )->freesSlot() ) {
				$families[ $row->progressId() ][ $row->id ] = $row;
			}
		}

		$dates = array();
		$now   = $this->clock->now();
		foreach ( $families as $sourceId => $parts ) {
			if ( count( $parts ) < 2 || ! isset( $parts[ $sourceId ] ) ) {
				continue;
			}
			foreach ( $parts as $id => $part ) {
				if ( $part->scheduledAt <= $now && LessonVisibility::Hidden->value !== $this->visibility->effectiveVisibility( $part ) ) {
					$dates[ $sourceId ][ $id ] = $part->scheduledAt;
				}
			}
			if ( isset( $dates[ $sourceId ] ) ) {
				asort( $dates[ $sourceId ], SORT_STRING );
			}
		}
		return $dates;
	}

	public function datesFor( GroupLessonDTO $row ): array {
		$this->groupDates[ $row->groupId ] ??= $this->lessonDates( $this->lessons->listByGroup( $row->groupId ) );
		return $this->groupDates[ $row->groupId ][ $row->progressId() ] ?? array();
	}

	/** Данные экрана результата сразу после сдачи и при повторном открытии плеера. */
	public function forSubmission( GroupLessonDTO $row, SubmissionDTO $sub ): array {
		return array_values( $this->forWork( $this->datesFor( $row ), $sub->studentPersonId, $row->progressId(), $sub->workId, $sub ) );
	}

	/**
	 * Каждая решённая задача принадлежит одному интервалу [начало части, начало следующей).
	 * Учитываем сохранённое время ответа, даже если весь пакет сдали позднее или из старой ссылки.
	 * ДЗ, решённое между встречами, остаётся на предыдущей встрече.
	 *
	 * @param array<int, string> $dates Даты одной темы из lessonDates().
	 * @return array<int, array{group_lesson_id:int, at:string, new_solved:?int, solved:?int, total:int, marks:string[]}>
	 */
	public function forWork( array $dates, int $personId, int $progressId, int $workId, ?SubmissionDTO $sub ): array {
		if ( array() === $dates ) {
			return array();
		}
		$work  = $this->works->get( $workId );
		$tasks = $sub ? $this->marks->submissionTasks( $sub ) : array();
		foreach ( $work?->itemIds ?? array() as $id ) {
			$tasks[ $id ] ??= array( 'mark' => 'remaining', 'submitted_at' => null );
		}
		if ( array() === $tasks ) {
			return array();
		}

		$answers = $sub ? $this->attempts->workAnswerTimes( $personId, $progressId, $workId ) : array();
		$checks  = $work?->workType->allowsInlineCheck() ? $this->checks->correctTimes( $personId, $progressId, $workId ) : array();
		$solvedAt = array();
		foreach ( $tasks as $id => $task ) {
			if ( empty( $task['recorded'] ) ) {
				$tasks[ $id ]['mark'] = 'remaining';
			}
			$check = $checks[ $id ] ?? null;
			// Верная проверка практики видна сразу, в том числе в ещё не сданном раунде.
			if ( null !== $check && $check['round'] > ( $sub?->attemptCount ?? 0 ) && ! $sub?->isCredited() ) {
				$tasks[ $id ]['mark'] = 'correct';
				$solvedAt[ $id ] = $check['at'];
			} elseif ( in_array( $task['mark'], WorkMarksService::SOLVED_MARKS, true ) ) {
				// Ручная проверка относится к ответу ученика, даже если преподаватель выставил балл позже.
				$at = $answers[ $id ]['correct'] ?? $answers[ $id ]['last'] ?? $task['submitted_at'];
				if ( null !== $check && ! $sub?->isCredited() && ( null === $at || $check['at'] < $at ) ) {
					$at = $check['at'];
				}
				// Дата последней пересдачи ничего не говорит о старых закрытых задачах.
				$solvedAt[ $id ] = $at ?? ( 1 === $sub?->attemptCount || $sub?->isCredited() ? $sub->submittedAt : null );
			}
		}

		$out       = array();
		$starts    = array_values( $dates );
		$previous  = 0;
		$onlyGrade = $sub && ! array_filter( array_column( $tasks, 'recorded' ) ) && ! $checks && ! $answers;
		$unknown   = $onlyGrade || in_array( null, $solvedAt, true );
		foreach ( array_keys( $dates ) as $index => $id ) {
			$next = $starts[ $index + 1 ] ?? null;
			$solved = 0;
			$marks  = array();
			foreach ( $tasks as $taskId => $task ) {
				$at = $solvedAt[ $taskId ] ?? null;
				$done = null !== $at && ( null === $next || $at < $next );
				if ( $done ) {
					++$solved;
				}
				$marks[] = $done || null === $next ? $task['mark'] : 'remaining';
			}
			$out[ $id ] = array(
				'group_lesson_id' => $id,
				'at'              => $starts[ $index ],
				'new_solved'      => $unknown ? null : $solved - $previous,
				'solved'          => $unknown ? ( null === $next && ! $onlyGrade ? count( $solvedAt ) : null ) : $solved,
				'total'           => count( $tasks ),
				'marks'           => $onlyGrade || ( $unknown && null !== $next ) ? array() : $marks,
			);
			$previous = $solved;
		}
		return $out;
	}
}
