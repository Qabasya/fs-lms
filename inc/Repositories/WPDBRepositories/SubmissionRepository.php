<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\Enums\Settings\TableName;

class SubmissionRepository {

	private \wpdb  $wpdb;
	private string $table;
	private string $glTable;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb    = $wpdb ?? $GLOBALS['wpdb'];
		$this->table   = TableName::Submissions->prefixed();
		$this->glTable = TableName::GroupLessons->prefixed();
	}

	public function create( SubmissionInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, $dto->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		$result = $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
		return false !== $result;
	}

	public function find( int $id ): ?SubmissionDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);
		return $row ? SubmissionDTO::fromArray( $row ) : null;
	}

	/** Поиск для дедупликации. */
	public function findForWork(
		int  $studentPersonId,
		int  $groupLessonId,
		int  $workId,
		?int $taskId = null
	): ?SubmissionDTO {
		$taskClause = null === $taskId ? 'task_id IS NULL' : 'task_id = %d';
		$params     = array( $this->table, $studentPersonId, $groupLessonId, $workId );
		if ( null !== $taskId ) {
			$params[] = $taskId;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND work_id = %d AND $taskClause LIMIT 1",
			$params
		);
		// phpcs:enable
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return $row ? SubmissionDTO::fromArray( $row ) : null;
	}

	/** @return SubmissionDTO[] */
	/** Есть ли хотя бы одна сдача по строке доставки (D17.3: guard вовлечённости). */
	public function hasAnyByGroupLesson( int $groupLessonId ): bool {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT EXISTS(SELECT 1 FROM %i WHERE group_lesson_id = %d)',
				$this->table,
				$groupLessonId
			)
		) > 0;
	}

	public function listByStudentAndGroupLesson( int $studentPersonId, int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d ORDER BY created_at DESC',
				$this->table,
				$studentPersonId,
				$groupLessonId
			),
			ARRAY_A
		);
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Очередь проверки: сдачи группы с нужными статусами (JOIN group_lessons → group_id).
	 *
	 * @param  string[] $statuses
	 * @return SubmissionDTO[]
	 */
	public function listQueueByGroup( int $groupId, array $statuses = array( 'submitted' ) ): array {
		if ( empty( $statuses ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT s.* FROM %i s
			 INNER JOIN %i gl ON gl.id = s.group_lesson_id
			 WHERE gl.group_id = %d AND s.status IN ($placeholders)
			 ORDER BY s.submitted_at ASC",
			array_merge( [ $this->table, $this->glTable, $groupId ], $statuses )
		);
		// phpcs:enable
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** @return SubmissionDTO[] Оценённые сдачи группы для журнала. */
	public function listForGradebookByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT s.* FROM %i s
				 INNER JOIN %i gl ON gl.id = s.group_lesson_id
				 WHERE gl.group_id = %d AND s.status = 'graded'
				 ORDER BY s.graded_at DESC",
				$this->table,
				$this->glTable,
				$groupId
			),
			ARRAY_A
		);
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** @return SubmissionDTO[] Оценённые сдачи ученика для журнала. */
	public function listForGradebookByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE student_person_id = %d AND status = 'graded' ORDER BY graded_at DESC",
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** @return SubmissionDTO[] Per-task строки пакетной сдачи (task_id IS NOT NULL). */
	public function listPerTaskByStudentWorkLesson( int $studentPersonId, int $groupLessonId, int $workId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND work_id = %d AND task_id IS NOT NULL',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$workId
			),
			ARRAY_A
		);
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/** Агрегатная строка пакетной сдачи (task_id IS NULL). */
	public function findAggregate( int $studentPersonId, int $groupLessonId, int $workId ): ?SubmissionDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND group_lesson_id = %d AND work_id = %d AND task_id IS NULL LIMIT 1',
				$this->table,
				$studentPersonId,
				$groupLessonId,
				$workId
			),
			ARRAY_A
		);
		return $row ? SubmissionDTO::fromArray( $row ) : null;
	}

	/** Каскадная очистка при удалении занятия (GroupDeletionHandler). */
	public function deleteAllByGroupLesson( int $groupLessonId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}
}
