<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Settings\TableName;

class ExamParticipationRepository extends AbstractExamRepository {

	private string $table;
	private string $participantsTable;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table             = TableName::ExamParticipations->prefixed();
		$this->participantsTable = TableName::ExamParticipants->prefixed();
	}

	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamParticipationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) ) );
	}

	/**
	 * Блокирующее чтение участия — общая точка сериализации для старта, сохранения ответа, сдачи,
	 * отмены и неявки. Вызывать только внутри транзакции.
	 */
	public function findForUpdate( int $id ): ?ExamParticipationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->table, $id ) ) );
	}

	public function findByEventAndParticipant( int $eventId, int $participantId ): ?ExamParticipationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE event_id = %d AND participant_id = %d',
			$this->table,
			$eventId,
			$participantId
		) ) );
	}

	/** Участие ученика в проведении: ученик → участник (по `person_id`) → участие. */
	public function findByEventAndPerson( int $eventId, int $personId ): ?ExamParticipationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare(
			'SELECT p.* FROM %i p INNER JOIN %i pt ON pt.id = p.participant_id WHERE p.event_id = %d AND pt.person_id = %d',
			$this->table,
			$this->participantsTable,
			$eventId,
			$personId
		) ) );
	}

	/** Участие ученика, к которому привязана попытка/запись (по ID участия и человеку). */
	public function findByIdAndPerson( int $participationId, int $personId ): ?ExamParticipationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare(
			'SELECT p.* FROM %i p INNER JOIN %i pt ON pt.id = p.participant_id WHERE p.id = %d AND pt.person_id = %d',
			$this->table,
			$this->participantsTable,
			$participationId,
			$personId
		) ) );
	}

	/** @return ExamParticipationDTO[] */
	public function findByEvent( int $eventId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d ORDER BY id ASC', $this->table, $eventId ) );
		return array_map( array( ExamParticipationDTO::class, 'fromArray' ), $rows );
	}

	/** @return int[] ID проведений, в которых у ученика есть участие. */
	public function findEventIdsForPerson( int $personId ): array {
		return $this->readInts( $this->wpdb->prepare(
			'SELECT DISTINCT p.event_id FROM %i p INNER JOIN %i pt ON pt.id = p.participant_id WHERE pt.person_id = %d',
			$this->table,
			$this->participantsTable,
			$personId
		) );
	}

	/**
	 * Находит или создаёт участие и сразу блокирует строку: `INSERT IGNORE` по уникальному
	 * `(event_id, participant_id)`, затем `SELECT … FOR UPDATE`. Вызывать внутри транзакции.
	 */
	public function getOrCreateLocked( int $eventId, int $participantId, ExamAudience $audience ): ExamParticipationDTO {
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->write( $this->wpdb->prepare(
			'INSERT IGNORE INTO %i ( event_id, participant_id, audience, created_at, updated_at ) VALUES ( %d, %d, %s, %s, %s )',
			$this->table,
			$eventId,
			$participantId,
			$audience->value,
			$now,
			$now
		) );

		$participation = $this->hydrate( $this->readRow( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE event_id = %d AND participant_id = %d FOR UPDATE',
			$this->table,
			$eventId,
			$participantId
		) ) );
		if ( null === $participation ) {
			throw new \RuntimeException( 'Не удалось получить участие в проведении.' );
		}
		return $participation;
	}

	public function setCurrentAttempt( int $participationId, ?int $attemptId ): void {
		$this->setNullableInt( 'current_attempt_id', $participationId, $attemptId );
	}

	public function setActiveRegistration( int $participationId, ?int $registrationId ): void {
		$this->setNullableInt( 'active_registration_id', $participationId, $registrationId );
	}

	/** Допуск гостя на площадке: отметка и автор; `null` снимает допуск. `version` не меняет — допуск не указатель вкладки. */
	public function setAdmission( int $participationId, ?string $atUtc, ?int $byUserId ): void {
		if ( null === $atUtc ) {
			$sql = $this->wpdb->prepare( 'UPDATE %i SET admitted_at = NULL, admitted_by_user_id = NULL, updated_at = %s WHERE id = %d', $this->table, gmdate( 'Y-m-d H:i:s' ), $participationId );
		} else {
			$sql = $this->wpdb->prepare( 'UPDATE %i SET admitted_at = %s, admitted_by_user_id = %d, updated_at = %s WHERE id = %d', $this->table, $atUtc, (int) $byUserId, gmdate( 'Y-m-d H:i:s' ), $participationId );
		}
		$this->write( $sql );
	}

	/**
	 * Источник приглашения, по которому гость записан. Пишется один раз при подтверждении заявки; `version` не меняет:
	 * источник — не указатель, по которому вкладка проверяет устаревшее состояние.
	 */
	public function setSource( int $participationId, int $sourceId ): void {
		$this->write( $this->wpdb->prepare(
			'UPDATE %i SET source_id = %d, updated_at = %s WHERE id = %d',
			$this->table,
			$sourceId,
			gmdate( 'Y-m-d H:i:s' ),
			$participationId
		) );
	}

	/**
	 * `wpdb::update()` не умеет писать NULL через формат — поэтому отдельный запрос.
	 * Любое изменение указателя участия увеличивает `version`: по нему вкладка с устаревшим
	 * состоянием узнаёт, что действует не над той записью (`ExamStale`).
	 */
	private function setNullableInt( string $column, int $participationId, ?int $value ): void {
		$allowed = array( 'current_attempt_id', 'active_registration_id' );
		if ( ! in_array( $column, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Недопустимая колонка участия.' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		if ( null === $value ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имя колонки из белого списка выше.
			$sql = $this->wpdb->prepare( "UPDATE %i SET {$column} = NULL, version = version + 1, updated_at = %s WHERE id = %d", $this->table, $now, $participationId );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имя колонки из белого списка выше.
			$sql = $this->wpdb->prepare( "UPDATE %i SET {$column} = %d, version = version + 1, updated_at = %s WHERE id = %d", $this->table, $value, $now, $participationId );
		}
		// Версия растёт всегда, поэтому у существующей строки затронута ровно одна.
		if ( 1 !== $this->write( $sql ) ) {
			throw new \RuntimeException( 'Участие в проведении не найдено.' );
		}
	}

	/** @param array<string, mixed>|null $row */
	private function hydrate( ?array $row ): ?ExamParticipationDTO {
		return null !== $row ? ExamParticipationDTO::fromArray( $row ) : null;
	}
}
