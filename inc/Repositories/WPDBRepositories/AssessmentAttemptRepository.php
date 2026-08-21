<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Assessment\AttemptInputDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Settings\TableName;

class AssessmentAttemptRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::AssessmentAttempts->prefixed();
	}

	public function create( AttemptInputDTO $dto ): int {
		$this->wpdb->insert( $this->table, [
			'assessment_id'     => $dto->assessmentId,
			'student_person_id' => $dto->studentPersonId,
			'group_id'          => $dto->groupId,
			'group_lesson_id'   => $dto->groupLessonId,
			'attempt_number'    => $dto->attemptNumber,
			'started_at'        => $dto->startedAt,
			'deadline_at'       => $dto->deadlineAt,
			'status'            => $dto->status->value,
		] );
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?AttemptDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);
		return $row ? AttemptDTO::fromArray( $row ) : null;
	}

	public function update( int $id, array $data ): bool {
		$result = $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
		return false !== $result;
	}

	/** D18: учитель подтверждает результат — открывает ответы/баллы ученику. */
	public function approve( int $id, int $approvedByUserId, string $approvedAt ): bool {
		return $this->update( $id, [
			'approved_at'         => $approvedAt,
			'approved_by_user_id' => $approvedByUserId,
		] );
	}

	/** Активная (in_progress, не просроченная) попытка студента по контрольной. */
	public function findActive( int $studentPersonId, int $assessmentId ): ?AttemptDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i
				WHERE student_person_id = %d
				  AND assessment_id = %d
				  AND status = 'in_progress'
				  AND deadline_at > NOW()
				ORDER BY id DESC
				LIMIT 1",
				$this->table,
				$studentPersonId,
				$assessmentId
			),
			ARRAY_A
		);
		return $row ? AttemptDTO::fromArray( $row ) : null;
	}

	/** Последняя завершённая (submitted/graded) попытка студента (T13.7). */
	public function findLastSubmitted( int $studentPersonId, int $assessmentId ): ?AttemptDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i
				WHERE student_person_id = %d
				  AND assessment_id = %d
				  AND status IN ('submitted', 'graded')
				ORDER BY id DESC
				LIMIT 1",
				$this->table,
				$studentPersonId,
				$assessmentId
			),
			ARRAY_A
		);
		return $row ? AttemptDTO::fromArray( $row ) : null;
	}

	/** @return AttemptDTO[] */
	/**
	 * Все попытки занятия — по всем контрольным и ученикам.
	 * Порядок пригоден для группировки: контрольная → ученик → номер попытки.
	 *
	 * @return AttemptDTO[]
	 */
	public function listByGroupLesson( int $groupLessonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_lesson_id = %d ORDER BY assessment_id ASC, student_person_id ASC, attempt_number ASC',
				$this->table,
				$groupLessonId,
			),
			ARRAY_A
		);

		return array_map( array( AttemptDTO::class, 'fromArray' ), $rows ?: array() );
	}

	public function listByStudentAndAssessment( int $studentPersonId, int $assessmentId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND assessment_id = %d ORDER BY attempt_number ASC',
				$this->table,
				$studentPersonId,
				$assessmentId
			),
			ARRAY_A
		);
		return array_map( [ AttemptDTO::class, 'fromArray' ], $rows ?: [] );
	}

	/** Следующий номер попытки. Вызывается внутри транзакции (AttemptService). */
	public function nextAttemptNumber( int $studentPersonId, int $assessmentId ): int {
		$max = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COALESCE(MAX(attempt_number), 0) FROM %i WHERE student_person_id = %d AND assessment_id = %d',
				$this->table,
				$studentPersonId,
				$assessmentId
			)
		);
		return (int) $max + 1;
	}

	/** Помечает просроченные попытки как expired. Возвращает кол-во обновлённых строк. */
	public function expireOverdue(): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET status = 'expired', updated_at = NOW() WHERE status = 'in_progress' AND deadline_at < NOW()",
				$this->table
			)
		);
		return (int) $this->wpdb->rows_affected;
	}

	/**
	 * Попытки группы со статусом graded|submitted — для журнала оценок.
	 *
	 * @return AttemptDTO[]
	 */
	public function listByGroupForGradebook( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE group_id = %d AND status IN ('graded','submitted') ORDER BY id ASC",
				$this->table,
				$groupId
			),
			ARRAY_A
		);
		return array_map( [ AttemptDTO::class, 'fromArray' ], $rows ?: [] );
	}

	/**
	 * Попытки НЕСКОЛЬКИХ групп со статусом graded|submitted — для вкладки «Работы»
	 * (D3, .docs/Tasks.md): один запрос вместо цикла по группам пользователя
	 * (`listByGroupForGradebook()` — только одна группа).
	 *
	 * @param int[] $groupIds
	 *
	 * @return AttemptDTO[]
	 */
	public function listByGroupsForGradebook( array $groupIds ): array {
		if ( empty( $groupIds ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $groupIds ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE group_id IN ($placeholders) AND status IN ('graded','submitted') ORDER BY id ASC",
			array_merge( array( $this->table ), $groupIds )
		);
		// phpcs:enable
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( AttemptDTO::class, 'fromArray' ), $rows ?: array() );
	}

	/**
	 * Попытки студента со статусом graded|submitted — для журнала оценок.
	 *
	 * @return AttemptDTO[]
	 */
	public function listByStudentForGradebook( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE student_person_id = %d AND status IN ('graded','submitted') ORDER BY id ASC",
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);
		return array_map( [ AttemptDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function countByAssessmentAndStudent( int $assessmentId, int $studentPersonId ): int {
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE assessment_id = %d AND student_person_id = %d',
				$this->table,
				$assessmentId,
				$studentPersonId
			)
		);
		return (int) $count;
	}

	/**
	 * Находит любую активную (in_progress, не просроченную) попытку ученика.
	 * Используется ExamLockService для блокировки контента на время экзамена.
	 */
	public function findAnyActive( int $studentPersonId ): ?AttemptDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i
				WHERE student_person_id = %d
				  AND status = 'in_progress'
				  AND deadline_at > NOW()
				ORDER BY id DESC
				LIMIT 1",
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);
		return $row ? AttemptDTO::fromArray( $row ) : null;
	}

	/** @return int[] Для каскадной очистки answers перед удалением попыток группы. */
	public function listIdsByGroup( int $groupId ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE group_id = %d', $this->table, $groupId )
		);
		return array_map( 'intval', $ids ?: array() );
	}

	/** Каскадная очистка при удалении группы (GroupDeletionHandler). */
	public function deleteAllByGroup( int $groupId ): int {
		return (int) $this->wpdb->delete( $this->table, array( 'group_id' => $groupId ) );
	}

	/** Удаление одной попытки по id (задача 11: сброс попыток ученика преподавателем). */
	public function delete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}
}
