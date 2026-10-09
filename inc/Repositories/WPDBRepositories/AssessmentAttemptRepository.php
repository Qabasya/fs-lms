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
			'assessment_id'        => $dto->assessmentId,
			'student_person_id'    => $dto->studentPersonId,
			'group_id'             => $dto->groupId,
			'group_lesson_id'      => $dto->groupLessonId,
			'attempt_number'       => $dto->attemptNumber,
			'started_at'           => $dto->startedAt,
			'deadline_at'          => $dto->deadlineAt,
			'status'               => $dto->status->value,
			'exam_participation_id' => $dto->examParticipationId,
			'exam_registration_id'  => $dto->examRegistrationId,
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

	/**
	 * Увеличивает версию результата, если она ещё равна ожидаемой (защита от двух проверяющих, 8.4.5).
	 * Условный `UPDATE`: затронута ровно одна строка — версия поднята; иначе кто-то уже изменил результат.
	 *
	 * @throws \RuntimeException Сбой запроса — это не «версия устарела».
	 */
	public function bumpResultVersion( int $id, int $expected ): bool {
		$affected = $this->wpdb->query( $this->wpdb->prepare(
			'UPDATE %i SET result_version = result_version + 1 WHERE id = %d AND result_version = %d',
			$this->table,
			$id,
			$expected
		) );
		if ( false === $affected ) {
			throw new \RuntimeException( 'Не удалось обновить версию результата попытки.' );
		}

		return 1 === (int) $affected;
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
				  AND exam_participation_id IS NULL
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
				  AND exam_participation_id IS NULL
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
				'SELECT * FROM %i WHERE group_lesson_id = %d AND exam_participation_id IS NULL ORDER BY assessment_id ASC, student_person_id ASC, attempt_number ASC',
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
				'SELECT * FROM %i WHERE student_person_id = %d AND assessment_id = %d AND exam_participation_id IS NULL ORDER BY attempt_number ASC',
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
				"UPDATE %i SET status = 'expired', updated_at = NOW() WHERE status = 'in_progress' AND deadline_at < NOW() AND exam_participation_id IS NULL",
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
				"SELECT * FROM %i WHERE group_id = %d AND status IN ('graded','submitted') AND exam_participation_id IS NULL ORDER BY id ASC",
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
			"SELECT * FROM %i WHERE group_id IN ($placeholders) AND status IN ('graded','submitted') AND exam_participation_id IS NULL ORDER BY id ASC",
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
				"SELECT * FROM %i WHERE student_person_id = %d AND status IN ('graded','submitted') AND exam_participation_id IS NULL ORDER BY id ASC",
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
				'SELECT COUNT(*) FROM %i WHERE assessment_id = %d AND student_person_id = %d AND exam_participation_id IS NULL',
				$this->table,
				$assessmentId,
				$studentPersonId
			)
		);
		return (int) $count;
	}

	/**
	 * Находит любую активную (in_progress, не просроченную) попытку ученика.
	 * Используется ExamLockService для блокировки контента на время экзамена и стартом экзамена.
	 *
	 * `deadline_at` хранится в местном времени сайта, поэтому «сейчас» приходит параметром (`ClockInterface::now()`),
	 * а не `NOW()` базы: часовой пояс сервера БД с поясом сайта может расходиться (на dev — на 3 часа).
	 *
	 * @param string $nowLocal Текущее местное время сайта (формат MySQL)
	 */
	public function findAnyActive( int $studentPersonId, string $nowLocal ): ?AttemptDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i
				WHERE student_person_id = %d
				  AND status = 'in_progress'
				  AND deadline_at > %s
				ORDER BY id DESC
				LIMIT 1",
				$this->table,
				$studentPersonId,
				$nowLocal
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

	/**
	 * Попытка экзамена по участию (6.1, 6.2).
	 *
	 * @return ?AttemptDTO
	 */
	public function findByParticipation( int $participationId ): ?AttemptDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE exam_participation_id = %d',
				$this->table,
				$participationId
			),
			ARRAY_A
		);
		return $row ? AttemptDTO::fromArray( $row ) : null;
	}

	/**
	 * Попытки экзамена по нескольким участиям (для фильтра в картах экзаменов).
	 *
	 * @param int[] $participationIds
	 *
	 * @return AttemptDTO[]
	 */
	public function listByParticipations( array $participationIds ): array {
		if ( empty( $participationIds ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $participationIds ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE exam_participation_id IN ($placeholders)",
			array_merge( array( $this->table ), $participationIds )
		);
		// phpcs:enable
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return array_map( [ AttemptDTO::class, 'fromArray' ], $rows ?: [] );
	}

	/**
	 * Сколько незавершённых экзаменных попыток этого варианта в проведении (участие → проведение).
	 * Пока они есть, снимок варианта пересобирать нельзя: у идущих попыток уже зафиксированы состав и длительность.
	 */
	public function countInProgressExamByEvent( int $eventId, int $assessmentId ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i a INNER JOIN %i p ON p.id = a.exam_participation_id
			 WHERE p.event_id = %d AND a.assessment_id = %d AND a.status = %s',
			$this->table,
			TableName::ExamParticipations->prefixed(),
			$eventId,
			$assessmentId,
			AttemptStatus::InProgress->value
		) );
	}

	/** Сколько незавершённых экзаменных попыток во всём проведении (любой вариант). */
	public function countInProgressExamByEventAll( int $eventId ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i a INNER JOIN %i p ON p.id = a.exam_participation_id WHERE p.event_id = %d AND a.status = %s',
			$this->table,
			TableName::ExamParticipations->prefixed(),
			$eventId,
			AttemptStatus::InProgress->value
		) );
	}

	/**
	 * ID просроченных активных экзаменных попыток для автоистечения (6.2).
	 *
	 * @param string $nowLocal Локальное время (формат MySQL)
	 * @param int    $limit    Максимум результатов
	 *
	 * @return int[]
	 */
	public function listOverdueExamIds( string $nowLocal, int $limit = 200 ): array {
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND deadline_at < %s AND exam_participation_id IS NOT NULL ORDER BY id ASC LIMIT %d',
				$this->table,
				'in_progress',
				$nowLocal,
				$limit
			)
		);
		return array_map( 'intval', $ids ?: array() );
	}
}
