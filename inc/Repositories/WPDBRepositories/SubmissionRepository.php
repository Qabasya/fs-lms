<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Course\SubmissionDTO;
use Inc\DTO\Course\SubmissionInputDTO;
use Inc\Enums\Settings\TableName;
use Inc\Enums\Course\SubmissionStatus;

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

	/** Пакет сданных агрегатов учебных работ; опросы и экзаменные попытки не входят. */
	public function listWorkAggregatesAfter( int $afterId, int $limit ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE id > %d AND task_id IS NULL AND work_type IN (%s, %s, %s) AND status IN (%s, %s) ORDER BY id ASC LIMIT %d',
			$this->table,
			$afterId,
			\Inc\Enums\Course\WorkType::Practice->value,
			\Inc\Enums\Course\WorkType::Independent->value,
			\Inc\Enums\Course\WorkType::Homework->value,
			SubmissionStatus::Graded->value,
			SubmissionStatus::PendingReview->value,
			max( 1, $limit )
		), ARRAY_A );
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
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
	 * Read delivery facts in bounded batches, optionally restricted to one learner/aggregate rows.
	 *
	 * @param int[] $lessonIds Canonical progress IDs.
	 * @return array<int, SubmissionDTO[]> Rows keyed by delivery ID; absent deliveries have no entry.
	 */
	public function listByGroupLessons( array $lessonIds, ?int $studentId = null, bool $aggregatesOnly = false ): array {
		$result    = array();
		$lessonIds = array_values( array_unique( array_filter( array_map( 'intval', $lessonIds ), static fn( int $id ): bool => $id > 0 ) ) );
		foreach ( array_chunk( $lessonIds, 500 ) as $ids ) {
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$args         = array_merge( array( $this->table ), $ids );
			$where        = '';
			if ( null !== $studentId ) {
				$where .= ' AND student_person_id = %d';
				$args[] = $studentId;
			}
			if ( $aggregatesOnly ) {
				$where .= ' AND task_id IS NULL';
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Generated placeholders and fixed predicates only.
			$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i WHERE group_lesson_id IN ($placeholders)$where ORDER BY created_at DESC", $args ), ARRAY_A );
			foreach ( $rows ?? array() as $row ) {
				$submission                             = SubmissionDTO::fromArray( $row );
				$result[ $submission->groupLessonId ][] = $submission;
			}
		}
		return $result;
	}

	/**
	 * Очередь проверки: сдачи группы с нужными статусами (JOIN group_lessons → group_id).
	 *
	 * @param  string[] $statuses
	 * @return SubmissionDTO[]
	 */
/** Counts queues without hydrating submissions, for a bounded authorized group set. */
	public function queueCountsByGroups( array $groupIds ): array {
		if ( array() === $groupIds ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $groupIds ), '%d' ) );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT gl.group_id, COUNT(*) AS cnt FROM %i s JOIN %i gl ON gl.id = s.group_lesson_id
			 WHERE gl.group_id IN ($placeholders) AND s.status = %s GROUP BY gl.group_id",
			array_merge( array( $this->table, $this->glTable ), $groupIds, array( SubmissionStatus::Submitted->value ) )
		), ARRAY_A );
		return array_map( 'intval', array_column( $rows ?: array(), 'cnt', 'group_id' ) );
	}

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
	 * Сданные работы группы для журнала и «Сводки по ученику».
	 *
	 * `task_id IS NULL` — только агрегатные строки: per-task строки пакетной
	 * сдачи получают статус `graded` при ручной проверке задания
	 * ({@see \Inc\Services\Course\SubmissionService::gradeBatchTask()}) и иначе
	 * дублировали бы работу отдельными записями в журнале и «Сводке по ученику».
	 *
	 * Статусы: `graded` + ещё не проверенные `submitted`/`pending_review`.
	 * Раньше фильтр был только по `graded`, и сданная, но не оценённая работа
	 * пропадала отовсюду («Работ нет» в сводке при нескольких сдачах). Ячейка
	 * умеет показывать «На проверке» ({@see \Inc\DTO\Course\GradebookEntryDTO::displayValue()}),
	 * поэтому такие сдачи отдаём — источник помечает их `displayType = 'pending'`.
	 * `returned` (возвращена на доработку) не отдаём: результата у неё нет.
	 *
	 * @return SubmissionDTO[]
	 */
	public function listForGradebookByGroup( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT s.* FROM %i s
				 INNER JOIN %i gl ON gl.id = s.group_lesson_id
				 WHERE gl.group_id = %d AND s.task_id IS NULL
				   AND s.status IN ( 'graded', 'submitted', 'pending_review' )
				 ORDER BY COALESCE( s.graded_at, s.submitted_at ) DESC",
				$this->table,
				$this->glTable,
				$groupId
			),
			ARRAY_A
		);
		return array_map( [ SubmissionDTO::class, 'fromArray' ], $rows ?: array() );
	}

	/**
	 * Сданные работы ученика для журнала.
	 *
	 * @see listForGradebookByGroup() Почему только агрегатные строки (task_id IS NULL) и какие статусы
	 *
	 * @return SubmissionDTO[]
	 */
	public function listForGradebookByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i
				 WHERE student_person_id = %d AND task_id IS NULL
				   AND status IN ( 'graded', 'submitted', 'pending_review' )
				 ORDER BY COALESCE( graded_at, submitted_at ) DESC",
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

	/** @return SubmissionDTO[] Агрегатные сдачи (task_id IS NULL) всех учеников по занятию. */
	public function listAggregatesByGroupLesson( int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_lesson_id = %d AND task_id IS NULL',
				$this->table,
				$groupLessonId
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

	/**
	 * Пары «строка занятия + работа» с самой свежей сдачей — режим «Новые сдачи» экрана
	 * «Работы» (D3): работы выстраиваются по времени последней сдачи, а не по дате занятия.
	 * Порция — ключевой меткой (latest_at, group_lesson_id, work_id) по убыванию.
	 *
	 * Только агрегатные сдачи (task_id IS NULL): per-task строки пакетной сдачи дублировали бы
	 * работу. Только прошедшие занятия — как лента {@see GroupLessonRepository::listFeedPage()}.
	 *
	 * @param int[] $groupIds
	 *
	 * @return array<int, array{group_lesson_id:int, work_id:int, latest_at:string}>
	 */
	public function listWorkLatestPage(
		array $groupIds,
		string $now,
		?string $cursorAt,
		int $cursorLessonId,
		int $cursorWorkId,
		int $limit
	): array {
		if ( array() === $groupIds || $limit < 1 ) {
			return array();
		}
		$groupPlaceholders = implode( ', ', array_fill( 0, count( $groupIds ), '%d' ) );
		$args              = array_merge( array( $this->table, $this->glTable, $this->glTable ), $groupIds, array( $now ) );
		$cursorSql         = '';

		if ( null !== $cursorAt ) {
			$cursorSql = ' HAVING ( latest_at < %s OR ( latest_at = %s AND ( unit.id < %d OR ( unit.id = %d AND s.work_id < %d ) ) ) )';
			array_push( $args, $cursorAt, $cursorAt, $cursorLessonId, $cursorLessonId, $cursorWorkId );
		}
		$args[] = $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT unit.id AS group_lesson_id, s.work_id AS work_id, MAX( s.submitted_at ) AS latest_at
			 FROM %i s
			 INNER JOIN %i gl ON gl.id = s.group_lesson_id
			 INNER JOIN %i unit ON unit.id = CASE
			 WHEN gl.extra_key IS NOT NULL AND gl.scheduled_at IS NOT NULL THEN
			 (SELECT MAX(sibling.id) FROM {$this->glTable} sibling
			 WHERE sibling.group_id = gl.group_id AND sibling.extra_key = gl.extra_key AND sibling.scheduled_at = gl.scheduled_at)
			 ELSE gl.id END
			 WHERE gl.group_id IN ($groupPlaceholders) AND s.task_id IS NULL AND s.submitted_at IS NOT NULL
			   AND ( gl.scheduled_at IS NULL OR gl.scheduled_at <= %s )
			 GROUP BY unit.id, s.work_id
			 $cursorSql
			 ORDER BY latest_at DESC, group_lesson_id DESC, work_id DESC
			 LIMIT %d",
			$args
		);
		// phpcs:enable
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map(
			static fn( array $r ): array => array(
				'group_lesson_id' => (int) $r['group_lesson_id'],
				'work_id'         => (int) $r['work_id'],
				'latest_at'       => (string) $r['latest_at'],
			),
			$rows ?: array()
		);
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
	/** Completed work references without hydrating submission DTOs. */
	public function completedWorksForLessons( int $studentId, array $lessonIds ): array {
		$result = array();
		foreach ( array_chunk( array_values( array_unique( $lessonIds ) ), 500 ) as $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows = $this->wpdb->get_results( $this->wpdb->prepare(
				"SELECT DISTINCT group_lesson_id, work_id FROM %i WHERE student_person_id = %d AND group_lesson_id IN ($placeholders) AND status IN (%s, %s)",
				array_merge( array( $this->table, $studentId ), $ids, array( SubmissionStatus::Submitted->value, SubmissionStatus::Graded->value ) )
			), ARRAY_A );
			foreach ( $rows ?: array() as $row ) {
				$result[ (int) $row['group_lesson_id'] ][ (int) $row['work_id'] ] = true;
			}
		}
		return $result;
	}

}
