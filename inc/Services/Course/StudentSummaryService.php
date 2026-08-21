<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\ProgressStatus;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

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

	public function __construct(
		private readonly GroupLessonRepository $groupLessons,
		private readonly LessonManager         $lessons,
		private readonly AttendanceService     $attendance,
		private readonly GradebookService      $gradebook,
		private readonly GroupsRepository      $groups,
		private readonly LessonProgressService $progress,
	) {}

	/**
	 * @return array{lessons: array<int, array{
	 *   group_lesson_id:int, date:string, topic:string, kind:string,
	 *   attendance:string, progress:?array{total:int, done:int, failed:int, open:int},
	 *   works: array<int, array{badge:?string, value:string, display:string, title:string, source_type:string, source_id:int, overdue:bool, category:string, score:?float, max_score:?float, graded_at:?string, group_key:?string}>
	 * }>}
	 */
	public function forStudent( int $groupId, int $personId ): array {
		$lessons = array();

		// Эпик 15: открытая группа — занятия не датируются, включаем всю программу
		// (порядок программы), посещаемость не показывается.
		$group  = $this->groups->findById( $groupId );
		$isOpen = $group && AccessMode::Open === AccessMode::fromValueOrDefault( (string) ( $group->access_mode ?? '' ) );

		// Занятия: групповые датированные + личные индивидуальные этого ученика.
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $gl ) {
			if ( $gl->kind->isIndividual() && $gl->studentPersonId !== $personId ) {
				continue;
			}
			if ( ! $gl->scheduledAt && ! $isOpen ) {
				continue;
			}
			$lesson              = $gl->lessonId ? $this->lessons->get( $gl->lessonId ) : null;
			$lessons[ $gl->id ] = array(
				'group_lesson_id' => $gl->id,
				'date'            => $gl->scheduledAt ? substr( $gl->scheduledAt, 0, 10 ) : '',
				'topic'           => $lesson?->topic ?? ( $gl->label ?? '' ),
				'kind'            => $gl->kind->value,
				'attendance'      => 'none',
				// Открытые группы (D8) и label-занятия без lessonId — прогресс не считаем.
				'progress'        => ( ! $isOpen && $gl->lessonId ) ? $this->lessonProgress( $personId, $gl->id ) : null,
				'works'           => array(),
			);
		}

		// Посещаемость (+/−) по матрице группы.
		$matrix = $this->attendance->matrixForGroup( $groupId );
		foreach ( $lessons as $glid => &$row ) {
			if ( isset( $matrix[ $glid ][ $personId ] ) ) {
				$row['attendance'] = $matrix[ $glid ][ $personId ] ? 'present' : 'absent';
			}
		}
		unset( $row );

		// Работы ученика, разложенные по занятию (badge + сырой балл).
		foreach ( $this->gradebook->forGroup( $groupId ) as $entry ) {
			if ( $entry->studentPersonId !== $personId || null === $entry->groupLessonId ) {
				continue;
			}
			if ( ! isset( $lessons[ $entry->groupLessonId ] ) ) {
				continue;
			}
			$lessons[ $entry->groupLessonId ]['works'][] = array(
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
			);
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
