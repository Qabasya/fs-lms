<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\AttendanceDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class AttendanceRepository
 *
 * Доступ к таблице fs_lms_attendance. Бинарная посещаемость (D4):
 * одна запись на (занятие, ученик), upsert по UNIQUE (group_lesson_id, student_person_id).
 *
 * @package Inc\Repositories\WPDBRepositories
 */
class AttendanceRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Attendance->prefixed();
	}

	/**
	 * Ставит/обновляет отметку (занятие, ученик).
	 */
	public function upsert( int $groupLessonId, int $studentPersonId, bool $isPresent, int $markedBy ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO %i (group_lesson_id, student_person_id, is_present, marked_by, marked_at)
				 VALUES (%d, %d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE is_present = VALUES(is_present), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at)",
				$this->table,
				$groupLessonId,
				$studentPersonId,
				(int) $isPresent,
				$markedBy,
				current_time( 'mysql', true )
			)
		);
	}

	/** @return AttendanceDTO[] */
	public function listByGroupLesson( int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE group_lesson_id = %d', $this->table, $groupLessonId ),
			ARRAY_A
		);
		return array_map( array( AttendanceDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Все отметки группы (JOIN group_lessons по group_id).
	 *
	 * @return AttendanceDTO[]
	 */
	public function listByGroup( int $groupId ): array {
		$groupLessons = TableName::GroupLessons->prefixed();
		$rows         = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT a.* FROM %i a INNER JOIN %i gl ON gl.id = a.group_lesson_id WHERE gl.group_id = %d',
				$this->table,
				$groupLessons,
				$groupId
			),
			ARRAY_A
		);
		return array_map( array( AttendanceDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/** @return AttendanceDTO[] */
	public function listByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE student_person_id = %d', $this->table, $studentPersonId ),
			ARRAY_A
		);
		return array_map( array( AttendanceDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/** Каскадная очистка при удалении занятия (GroupDeletionHandler). */
	public function deleteAllByGroupLesson( int $groupLessonId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}
}
