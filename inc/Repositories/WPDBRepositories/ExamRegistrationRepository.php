<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\ExamSessionStatus;
use Inc\Enums\Settings\TableName;

class ExamRegistrationRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamRegistrations->prefixed();
	}

	/**
	 * @throws DuplicateKeyException У участия уже есть действующая запись (уникальный индекс).
	 */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamRegistrationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) ) );
	}

	/** @return ExamRegistrationDTO[] */
	public function findByParticipation( int $participationId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE participation_id = %d ORDER BY id ASC', $this->table, $participationId ) );
		return array_map( array( ExamRegistrationDTO::class, 'fromArray' ), $rows );
	}

	/** Действующая запись участия (`active_slot = 1`). */
	public function findActive( int $participationId ): ?ExamRegistrationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE participation_id = %d AND active_slot = 1',
			$this->table,
			$participationId
		) ) );
	}

	/** Запись по ключу идемпотентности внутри участия (повторный запрос с тем же ключом). */
	public function findByRequestKey( int $participationId, string $requestKey ): ?ExamRegistrationDTO {
		return $this->hydrate( $this->readRow( $this->wpdb->prepare(
			'SELECT * FROM %i WHERE participation_id = %d AND request_key = %s ORDER BY id DESC LIMIT 1',
			$this->table,
			$participationId,
			$requestKey
		) ) );
	}

	/**
	 * @param ExamRegistrationStatus[] $statuses Пусто — любые статусы.
	 *
	 * @return ExamRegistrationDTO[]
	 */
	public function listBySession( int $sessionId, array $statuses = array() ): array {
		if ( array() === $statuses ) {
			$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %d ORDER BY id ASC', $this->table, $sessionId ) );
		} else {
			$values       = array_map( static fn( ExamRegistrationStatus $s ): string => $s->value, $statuses );
			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$rows         = $this->readRows(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- плейсхолдеры собраны из числа статусов.
				$this->wpdb->prepare( "SELECT * FROM %i WHERE session_id = %d AND status IN ( {$placeholders} ) ORDER BY id ASC", $this->table, $sessionId, ...$values )
			);
		}
		return array_map( array( ExamRegistrationDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Закрывает действующую запись: ставит статус, сбрасывает `active_slot` в NULL (уникальный
	 * индекс `participation_active` допускает много NULL) и пишет отметку времени своего статуса.
	 *
	 * Срабатывает только на действующей записи — повторный вызов вернёт false и ничего не изменит.
	 *
	 * @return bool true — запись закрыта этим вызовом.
	 */
	public function deactivate( int $id, ExamRegistrationStatus $status, string $atUtc, ?string $reason = null, ?int $actorUserId = null ): bool {
		$timeColumn = match ( $status ) {
			ExamRegistrationStatus::Cancelled   => 'cancelled_at',
			ExamRegistrationStatus::Transferred => 'transferred_at',
			ExamRegistrationStatus::Missed      => 'missed_at',
			ExamRegistrationStatus::Confirmed   => throw new \InvalidArgumentException( 'Действующую запись нельзя «закрыть» в статус confirmed.' ),
		};

		$sql  = "UPDATE %i SET status = %s, active_slot = NULL, {$timeColumn} = %s";
		$args = array( $this->table, $status->value, $atUtc );
		if ( null !== $reason ) {
			$sql   .= ', reason = %s';
			$args[] = $reason;
		}
		if ( null !== $actorUserId ) {
			$sql   .= ', actor_user_id = %d';
			$args[] = $actorUserId;
		}
		$sql   .= ' WHERE id = %d AND active_slot = 1';
		$args[] = $id;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- имя колонки из закрытого match.
		return 1 === $this->write( $this->wpdb->prepare( $sql, ...$args ) );
	}

	/**
	 * Есть ли у участника действующая запись в ДРУГОМ проведении, пересекающаяся по времени (UTC).
	 * Надёжна только под блокировкой участника ({@see ExamParticipantRepository::findForUpdate()}).
	 */
	public function hasOverlappingActive( int $participantId, string $startUtc, string $endUtc, int $excludeEventId ): bool {
		return $this->readInt( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i r
			 INNER JOIN %i p ON p.id = r.participation_id
			 INNER JOIN %i s ON s.id = r.session_id
			 WHERE r.active_slot = 1 AND p.participant_id = %d AND p.event_id <> %d
			   AND s.scheduled_at < %s AND s.planned_end_at > %s',
			$this->table,
			TableName::ExamParticipations->prefixed(),
			TableName::ExamSessions->prefixed(),
			$participantId,
			$excludeEventId,
			$endUtc,
			$startUtc
		) ) > 0;
	}

	/** Сколько действующих записей во всех сеансах проведения — отмена проведения до этапа 8.3 разрешена только без них. */
	public function countActiveByEvent( int $eventId ): int {
		return $this->readInt( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i r INNER JOIN %i s ON s.id = r.session_id WHERE s.event_id = %d AND r.active_slot = 1',
			$this->table,
			TableName::ExamSessions->prefixed(),
			$eventId
		) );
	}

	/** Сколько действующих записей у сеанса — для сверки с `occupied_count`. */
	public function countActiveBySession( int $sessionId ): int {
		return $this->readInt( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE session_id = %d AND active_slot = 1', $this->table, $sessionId ) );
	}

	/**
	 * Сколько участий проведения имеют больше одной действующей записи. Уникальный индекс `(participation_id, active_slot)` не должен
	 * этого допускать: число — проверка стенда, что защита работает.
	 */
	public function countParticipationsWithMultipleActive( int $eventId ): int {
		return $this->readInt( $this->wpdb->prepare(
			'SELECT COUNT(*) FROM ( SELECT r.participation_id FROM %i r INNER JOIN %i p ON p.id = r.participation_id
			 WHERE p.event_id = %d AND r.active_slot = 1 GROUP BY r.participation_id HAVING COUNT(*) > 1 ) doubles',
			$this->table,
			TableName::ExamParticipations->prefixed(),
			$eventId
		) );
	}

	/**
	 * Действующие записи сеансов, плановый конец которых наступил (кандидаты в неявку).
	 *
	 * @return ExamRegistrationDTO[]
	 */
	public function listActiveOfEndedSessions( string $nowUtc, int $limit = 200 ): array {
		$rows = $this->readRows( $this->wpdb->prepare(
			'SELECT r.* FROM %i r INNER JOIN %i s ON s.id = r.session_id
			 WHERE r.active_slot = 1 AND s.status <> %s AND s.planned_end_at <= %s
			 ORDER BY r.id ASC LIMIT %d',
			$this->table,
			TableName::ExamSessions->prefixed(),
			ExamSessionStatus::Cancelled->value,
			$nowUtc,
			$limit
		) );
		return array_map( array( ExamRegistrationDTO::class, 'fromArray' ), $rows );
	}

	/** @param array<string, mixed>|null $row */
	private function hydrate( ?array $row ): ?ExamRegistrationDTO {
		return null !== $row ? ExamRegistrationDTO::fromArray( $row ) : null;
	}
}
