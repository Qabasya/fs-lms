<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;

/**
 * Class AttendanceService
 *
 * Посещаемость (D4): бинарно присутствовал/отсутствовал. Без баллов и весов.
 *
 * @package Inc\Services\Course
 */
class AttendanceService {

	public function __construct(
		private readonly AttendanceRepository    $attendance,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly StudentRecordRepository $records,
	) {}

	/** Отметка одного ученика на занятии. */
	public function mark( int $groupLessonId, int $studentPersonId, bool $present, int $actorUserId ): void {
		$this->attendance->upsert( $groupLessonId, $studentPersonId, $present, $actorUserId );
	}

	/**
	 * Отметить всех активных учеников группы на занятии (паттерн «всем present → флипнуть»).
	 */
	public function markAll( int $groupLessonId, bool $present, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return;
		}
		foreach ( $this->records->findActiveByGroupId( $row->groupId ) as $rec ) {
			$this->attendance->upsert( $groupLessonId, $rec->studentPersonId, $present, $actorUserId );
		}
	}

	/**
	 * Матрица посещаемости группы для журнала.
	 *
	 * @return array<int, array<int, bool>> groupLessonId => [studentPersonId => isPresent]
	 */
	public function matrixForGroup( int $groupId ): array {
		$matrix = array();
		foreach ( $this->attendance->listByGroup( $groupId ) as $a ) {
			$matrix[ $a->groupLessonId ][ $a->studentPersonId ] = $a->isPresent;
		}
		return $matrix;
	}
}
