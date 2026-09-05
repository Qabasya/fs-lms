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

	/**
	 * Оценённые сдачи группы для журнала.
	 *
	 * `task_id IS NULL` — только агрегатные строки: per-task строки пакетной
	 * сдачи получают статус `graded` при ручной проверке задания
	 * ({@see \Inc\Services\Course\SubmissionService::gradeBatchTask()}) и иначе
	 * дублировали бы работу отдельными записями в журнале и «Сводке по ученику».
	 *
	 * @return SubmissionDTO[]
	 */
	public function listForGradebookByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT s.* FROM %i s
				 INNER JOIN %i gl ON gl.id = s.group_lesson_id
				 WHERE gl.group_id = %d AND s.task_id IS NULL AND s.status = 'graded'
				 ORDER BY s.graded_at DESC",
				$this->table,
				$this->glTable,
				$groupId
			),
			ARRAY_A
		);
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Оценённые сдачи ученика для журнала.
	 *
	 * @see listForGradebookByGroup() Почему только агрегатные строки (task_id IS NULL)
	 *
	 * @return SubmissionDTO[]
	 */
	public function listForGradebookByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE student_person_id = %d AND task_id IS NULL AND status = 'graded' ORDER BY graded_at DESC",
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

	/**
	 * Сводка агрегатных сдач (task_id IS NULL) нужного статуса по НЕСКОЛЬКИМ группам —
	 * для вкладки «Работы» (D3, .docs/Tasks.md): один запрос вместо цикла по группам
	 * пользователя (`listQueueByGroup()` — только одна группа).
	 *
	 * @param int[]    $groupIds
	 * @param string[] $statuses
	 *
	 * @return array<int, array{work_id:int, work_type:string, group_id:int, cnt:int, latest_at:?string}>
	 */
	public function summaryByGroups( array $groupIds, array $statuses ): array {
		if ( empty( $groupIds ) || empty( $statuses ) ) {
			return array();
		}
		$groupPlaceholders  = implode( ', ', array_fill( 0, count( $groupIds ), '%d' ) );
		$statusPlaceholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT s.work_id AS work_id, s.work_type AS work_type, gl.group_id AS group_id, COUNT(*) AS cnt,
			        MAX(s.submitted_at) AS latest_at
			 FROM %i s
			 INNER JOIN %i gl ON gl.id = s.group_lesson_id
			 WHERE gl.group_id IN ($groupPlaceholders) AND s.task_id IS NULL AND s.status IN ($statusPlaceholders)
			 GROUP BY s.work_id, s.work_type, gl.group_id",
			array_merge( array( $this->table, $this->glTable ), $groupIds, $statuses )
		);
		// phpcs:enable
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static fn( array $r ): array => array(
				'work_id'   => (int) $r['work_id'],
				'work_type' => (string) $r['work_type'],
				'group_id'  => (int) $r['group_id'],
				'cnt'       => (int) $r['cnt'],
				'latest_at' => $r['latest_at'] ?? null,
			),
			$rows ?: array()
		);
	}

	/**
	 * Агрегатные сдачи конкретной работы нужного статуса по группам пользователя —
	 * список учеников для второго шага вкладки «Работы» (D3).
	 *
	 * @param int[]    $groupIds
	 * @param string[] $statuses
	 *
	 * @return SubmissionDTO[]
	 */
	public function listByWorkAndGroups( int $workId, array $groupIds, array $statuses ): array {
		if ( empty( $groupIds ) || empty( $statuses ) ) {
			return array();
		}
		$groupPlaceholders  = implode( ', ', array_fill( 0, count( $groupIds ), '%d' ) );
		$statusPlaceholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT s.* FROM %i s
			 INNER JOIN %i gl ON gl.id = s.group_lesson_id
			 WHERE gl.group_id IN ($groupPlaceholders) AND s.work_id = %d AND s.task_id IS NULL AND s.status IN ($statusPlaceholders)
			 ORDER BY s.submitted_at ASC",
			array_merge( array( $this->table, $this->glTable ), $groupIds, array( $workId ), $statuses )
		);
		// phpcs:enable
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( SubmissionDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/** Каскадная очистка при удалении занятия (GroupDeletionHandler). */
	public function deleteAllByGroupLesson( int $groupLessonId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_lesson_id' => $groupLessonId ) );
	}

	/**
	 * Удаляет все сдачи ученика по одной работе занятия (агрегат + per-task строки).
	 * Задача 11: сброс попыток ученика преподавателем.
	 */
	public function deleteAllByStudentWorkLesson( int $studentPersonId, int $groupLessonId, int $workId ): int {
		return (int) $this->wpdb->delete( $this->table, array(
			'student_person_id' => $studentPersonId,
			'group_lesson_id'   => $groupLessonId,
			'work_id'           => $workId,
		) );
	}
}
