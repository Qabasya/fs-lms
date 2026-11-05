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
	 * Ставит «присутствовал» только если отметки (занятие, ученик) ещё нет — отметку преподавателя не трогает.
	 *
	 * @return bool Отметка добавлена.
	 */
	public function insertPresentIfMissing( int $groupLessonId, int $studentPersonId, int $markedBy ): bool {
		return 1 === (int) $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT IGNORE INTO %i (group_lesson_id, student_person_id, is_present, marked_by, marked_at) VALUES (%d, %d, 1, %d, %s)',
				$this->table,
				$groupLessonId,
				$studentPersonId,
				$markedBy,
				current_time( 'mysql', true )
			)
		);
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

	/** Снимает отметку (занятие, ученик). */
	public function delete( int $groupLessonId, int $studentPersonId ): void {
		$this->wpdb->delete(
			$this->table,
			array( 'group_lesson_id' => $groupLessonId, 'student_person_id' => $studentPersonId ),
			array( '%d', '%d' )
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
	public function listByStudent( int $studentPersonId, bool $includeSelfPaced = true ): array {
		$rows = $this->wpdb->get_results(

			$includeSelfPaced
				? $this->wpdb->prepare( 'SELECT * FROM %i WHERE student_person_id = %d', $this->table, $studentPersonId )
				: $this->wpdb->prepare( 'SELECT a.* FROM %i a LEFT JOIN %i l ON l.id = a.group_lesson_id LEFT JOIN %i g ON g.id = l.group_id WHERE a.student_person_id = %d AND g.self_paced_course_id IS NULL', $this->table, TableName::GroupLessons->prefixed(), TableName::Groups->prefixed(), $studentPersonId ),
			ARRAY_A
		);
		return array_map( array( AttendanceDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Отметки «отсутствовал», поставленные в окне [from, to) — для уведомления
	 * «пропущено занятие» через 30 минут после Н. Границы — GMT, как и `marked_at`.
	 *
	 * @return AttendanceDTO[]
	 */
	public function listAbsentMarkedBetween( string $fromGmt, string $toGmt ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE is_present = 0 AND marked_at >= %s AND marked_at < %s',
				$this->table,
				$fromGmt,
				$toGmt
			),
			ARRAY_A
		);
		return array_map( array( AttendanceDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/** Каскадная очистка при удалении занятия (GroupDeletionHandler). */
	public function deleteAllByGroupLesson( int $groupLessonId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}
}
