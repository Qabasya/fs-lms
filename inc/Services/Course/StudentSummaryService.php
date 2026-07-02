<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

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
	) {}

	/**
	 * @return array{lessons: array<int, array{
	 *   group_lesson_id:int, date:string, topic:string, kind:string,
	 *   attendance:string,
	 *   works: array<int, array{badge:?string, value:string, display:string, title:string, source_type:string, source_id:int, overdue:bool}>
	 * }>}
	 */
	public function forStudent( int $groupId, int $personId ): array {
		$lessons = array();

		// Занятия: групповые датированные + личные индивидуальные этого ученика.
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $gl ) {
			if ( 'individual' === $gl->kind && $gl->studentPersonId !== $personId ) {
				continue;
			}
			if ( ! $gl->scheduledAt ) {
				continue;
			}
			$lesson              = $gl->lessonId ? $this->lessons->get( $gl->lessonId ) : null;
			$lessons[ $gl->id ] = array(
				'group_lesson_id' => $gl->id,
				'date'            => substr( $gl->scheduledAt, 0, 10 ),
				'topic'           => $lesson?->topic ?? ( $gl->label ?? '' ),
				'kind'            => $gl->kind,
				'attendance'      => 'none',
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
			);
		}

		// Свежие занятия сверху.
		$out = array_values( $lessons );
		usort( $out, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );

		return array( 'lessons' => $out );
	}
}
