<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\GradeBadge;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Course\WorkSourceType;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

/**
 * Class StudentSummaryService
 *
 * Read-модель «Сводка по ученику» (Эпик 10 T10.8, решение D8): по одному ученику —
 * его занятия (групповые датированные + личные индивидуальные) с посещаемостью и
 * результатами работ (badge + сырой балл из GradebookService, привязка через
 * `group_lesson_id` из T10.0b). Оценивание — в детали работы (T10.9).
 *
 * @package Inc\Services\Course
 */
class StudentSummaryService {

	/** Вердикт задания несданной работы — прочерк на красном в полоске карточки. */
	private const string MISSED = 'missed';

	public function __construct(
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
		private readonly AttendanceService       $attendance,
		private readonly GradebookService        $gradebook,
		private readonly GroupsRepository        $groups,
		private readonly LessonProgressService   $progress,
		private readonly WorkMarksService        $marks,
		private readonly HomeworkDeadlineService $deadlines,
		private readonly ClockInterface          $clock,
		private readonly SubmissionRepository    $submissions,
		private readonly ContinuationWorkProgressService $continuations,
	) {}

	/**
	 * @return array{lessons: array<int, array{
	 *   group_lesson_id:int, date:string, topic:string, kind:string,
	 *   attendance:string, progress:?array{total:int, done:int, failed:int, open:int},
	 *   works: array<int, array{badge:?string, value:string, display:string, title:string, source_type:string, source_id:int, overdue:bool, category:string, score:?float, max_score:?float, graded_at:?string, group_key:?string, marks:string[], submitted_at:?string}>
	 * }>}
	 */
	public function forStudent( int $groupId, int $personId ): array {
		$lessons = array();
		$rows    = array();

		// Эпик 15: открытая группа — занятия не датируются, включаем всю программу
		// (порядок программы), посещаемость не показывается.
		$group  = $this->groups->findById( $groupId );
		$now    = $this->clock->now();
		$isSelfPaced = ! empty( $group->self_paced_course_id );
		$isOpen = $group && AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );

		// Занятия: групповые датированные + личные индивидуальные этого ученика.
		$program = $this->groupLessons->listByGroup( $groupId );
		$dates = $this->continuations->lessonDates( $program );
		foreach ( $program as $gl ) {
			if ( $gl->kind->isIndividual() && $gl->studentPersonId !== $personId ) {
				continue;
			}
			if ( ! $gl->scheduledAt && ! $isOpen ) {
				continue;
			}
			// Только прошедшие занятия: будущие в сводке не нужны (в открытой группе дат нет).
			if ( ! $isOpen && $gl->scheduledAt > $now ) {
				continue;
			}
			$rows[ $gl->id ]     = $gl;
			$lesson              = $gl->lessonId ? $this->lessons->get( $gl->lessonId ) : null;
			$lessons[ $gl->id ] = array(
				'group_lesson_id' => $gl->id,
				'date'            => $gl->scheduledAt ? substr( $gl->scheduledAt, 0, 10 ) : '',
				'topic'           => $lesson?->topic ?? ( $gl->label ?? '' ),
				'kind'            => $gl->kind->value,
				'attendance'      => 'none',
				// В служебном курсе сохраняем прогресс; прежние открытые группы и label-занятия не меняем.
				'progress'        => ( ( ! $isOpen || $isSelfPaced ) && $gl->lessonId ) ? $this->lessonProgress( $personId, $gl->id ) : null,
				'works'           => array(),
			);
		}

		// Посещаемость (+/−) по матрице группы.
		$matrix = $isSelfPaced ? array() : $this->attendance->matrixForGroup( $groupId );
		foreach ( $lessons as $glid => &$row ) {
			if ( isset( $matrix[ $glid ][ $personId ] ) ) {
				$row['attendance'] = $matrix[ $glid ][ $personId ] ? 'present' : 'absent';
			}
		}
		unset( $row );

		// Работы ученика, разложенные по занятию (badge + сырой балл).
		$submitted = array();
		$results = array();
		foreach ( $this->gradebook->forGroup( $groupId ) as $entry ) {
			if ( $entry->studentPersonId !== $personId || null === $entry->groupLessonId ) {
				continue;
			}
			if ( ! isset( $lessons[ $entry->groupLessonId ] ) ) {
				continue;
			}
			// Ключ сдачи работы — `work:{id}` ({@see SubmissionGradeSource}).
			$sub      = null;
			$workId   = 0;
			$targets  = array( $entry->groupLessonId );
			if ( WorkSourceType::Submission->value === $entry->sourceType ) {
				$workId = (int) substr( (string) $entry->groupKey, 5 );
				$sub = $this->submissions->find( $entry->sourceId );
				if ( isset( $dates[ $entry->groupLessonId ] ) ) {
					$parts   = $dates[ $entry->groupLessonId ];
					$targets = array_keys( array_intersect_key( $parts, $lessons ) );
					$results[ $entry->groupLessonId ][ $workId ] = $this->continuations->forWork( $parts, $personId, $entry->groupLessonId, $workId, $sub );
				}
			}
			$card = array(
				'credited'        => $sub?->isCredited() ?? false,
				'work_id'         => $workId,
				'badge'       => $entry->badge?->badge(),
				'value'       => $entry->displayValue(),
				'display'     => $entry->displayType,
				'title'       => $entry->title,
				'source_type' => $entry->sourceType,
				'source_id'   => $entry->sourceId,
				// T12.2 (D13): постоянная метка «Просрочено» — сдано после дедлайна работы.
				'overdue'     => $entry->isLate,
				// Вкладка «Работы» (Tasks.md п.4): расширенный набор полей из GradebookEntryDTO.
				'category'    => $entry->category,
				'score'       => $entry->score,
				'max_score'   => $entry->maxScore,
				'graded_at'   => $entry->gradedAt,
				'group_key'   => $entry->groupKey,
				// Карточка работы (Tasks.md п.7): «полоска» вердиктов по заданиям
				// и время сдачи — без них карточка была бы просто строкой таблицы.
				'submitted_at' => $entry->submittedAt,
				'duration_sec' => $entry->durationSec,
			);
			foreach ( $targets as $id ) {
				if ( $workId ) {
					$submitted[ $id ][ $workId ] = true;
				}
				$result = $results[ $entry->groupLessonId ][ $workId ][ $id ] ?? null;
				$lessons[ $id ]['works'][] = $card + array(
					'group_lesson_id' => $id,
					'lesson_result'   => $result,
					'marks'           => $result['marks'] ?? $this->marks->marksFor( $entry->sourceType, $entry->sourceId ),
				);
			}
		}

		// Срок ДЗ, истёкший без сдачи: work_id → due_at (остальным работам срок в карточке не нужен).
		$overdue = array();
		foreach ( $this->deadlines->missed( $rows, $submitted, $now ) as $glid => $missed ) {
			foreach ( $missed as $m ) {
				$overdue[ $glid ][ $m['work']->id ] = $m['due_at'];
			}
		}

		// Каждая выданная на занятии работа без сдачи — карточка с прочерками.
		foreach ( $rows as $glid => $row ) {
			foreach ( $this->deadlines->assignedWorks( $row ) as $work ) {
				if ( isset( $submitted[ $glid ][ $work->id ] ) ) {
					continue;
				}
				$card = $this->missedCard( $work, $glid, $overdue[ $glid ][ $work->id ] ?? null );
				$source = $row->progressId();
				if ( isset( $dates[ $source ][ $glid ] ) ) {
					$results[ $source ][ $work->id ] ??= $this->continuations->forWork( $dates[ $source ], $personId, $source, $work->id, null );
					$card['lesson_result'] = $results[ $source ][ $work->id ][ $glid ] ?? null;
					$card['marks'] = $card['lesson_result']['marks'] ?? $card['marks'];
				}
				$lessons[ $glid ]['works'][] = $card;
			}
		}

		$out = array_values( $lessons );
		if ( $isOpen ) {
			// Открытая группа: дат нет — сохраняем порядок программы (position).
			return array( 'lessons' => $out, 'open' => true );
		}

		// Свежие занятия сверху.
		usort( $out, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );

		return array( 'lessons' => $out, 'open' => false );
	}

	/**
	 * Карточка работы без сдачи (не приступал или ДЗ не сдано к сроку): все задания — прочерком.
	 *
	 * @return array<string, mixed>
	 */
	private function missedCard( WorkDTO $work, int $groupLessonId, ?string $dueAt ): array {
		return array(
			'badge'        => GradeBadge::fromWorkType( $work->workType )->badge(),
			'value'        => 'Не сдано',
			'display'      => self::MISSED,
			'title'        => $work->title,
			'source_type'  => '',
			'source_id'    => 0,
			'overdue'      => false,
			'category'     => $work->workType->value,
			'score'        => null,
			'max_score'    => (float) count( $work->itemIds ),
			'graded_at'    => null,
			'group_key'    => 'work:' . $work->id,
			'marks'        => array_fill( 0, count( $work->itemIds ), self::MISSED ),
			'submitted_at' => null,
			'duration_sec' => null,
			'due_at'       => $dueAt,
			// Для «Засчитать» (PassedWorksService): работа и занятие, к которому она выдана.
			'work_id'         => $work->id,
			'group_lesson_id' => $groupLessonId,
		);
	}

	/**
	 * Компактный агрегат прогресса урока для строки списка занятий (Tasks.md
	 * п.3): счётчик done/open + признак наличия failed-шагов, без поштучной
	 * детализации по шагам.
	 *
	 * @return array{total:int, done:int, failed:int, open:int}|null
	 */
	private function lessonProgress( int $personId, int $groupLessonId ): ?array {
		$statuses = $this->progress->getStepStatuses( $personId, $groupLessonId );
		if ( empty( $statuses ) ) {
			return null;
		}

		$done   = 0;
		$failed = 0;
		foreach ( $statuses as $status ) {
			if ( ProgressStatus::Completed === $status ) {
				++$done;
			} elseif ( ProgressStatus::Failed === $status ) {
				++$failed;
			}
		}

		return array(
			'total'  => count( $statuses ),
			'done'   => $done,
			'failed' => $failed,
			'open'   => count( $statuses ) - $done - $failed,
		);
	}
}
