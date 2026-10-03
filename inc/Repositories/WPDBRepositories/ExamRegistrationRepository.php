<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Exam\RegistrationStatus;
use Inc\Enums\Settings\TableName;

class ExamRegistrationRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::ExamRegistrations->prefixed();
	}

	public function insert( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?ExamRegistrationDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->table, $id ),
			ARRAY_A
		);
		return $row ? ExamRegistrationDTO::fromArray( $row ) : null;
	}

	public function findByParticipation( int $participationId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE participation_id = %d", $this->table, $participationId ),
			ARRAY_A
		);
		return array_map( [ ExamRegistrationDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function findActive( int $participationId ): ?ExamRegistrationDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE participation_id = %d AND active_slot = 1", $this->table, $participationId ),
			ARRAY_A
		);
		return $row ? ExamRegistrationDTO::fromArray( $row ) : null;
	}

	public function findActiveByEventAndStudent( int $eventId, int $personId ): ?array {
		// Найти participation для студента
		$participationRepo = new ExamParticipationRepository();
		$participation = $participationRepo->findByEventAndStudent( $eventId, $personId );
		if ( ! $participation ) {
			return null;
		}

		// Найти активную запись для participation
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE participation_id = %d AND active_slot = 1",
				$this->table,
				$participation->id
			),
			ARRAY_A
		);
		return $row;
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}

	/**
	 * Отменить запись (для неявок и отмен) (6.3).
	 *
	 * @param int    $id Запись
	 * @param RegistrationStatus $status Новый статус
	 * @param string $nowUtc Время UTC
	 *
	 * @return bool Была ли обновлена
	 */
	public function deactivate( int $id, RegistrationStatus $status, string $nowUtc ): bool {
		return false !== $this->wpdb->update(
			$this->table,
			[
				'active_slot' => 0,
				'status' => $status->value,
				'updated_at' => $nowUtc,
			],
			[ 'id' => $id, 'active_slot' => 1 ]
		);
	}

	/**
	 * Освободить место в сеансе (6.3).
	 *
	 * @param int $sessionId Сеанс
	 */
	public function releaseSeat( int $sessionId ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE %i SET occupied_count = GREATEST(0, occupied_count - 1) WHERE id = %d",
				new \Inc\Repositories\WPDBRepositories\ExamSessionRepository()->table(),
				$sessionId
			)
		);
	}

	/**
	 * Активные записи для сеансов, которые закончились (6.3).
	 *
	 * @param string $nowUtc Время UTC
	 * @param int    $limit Лимит результатов
	 *
	 * @return ExamRegistrationDTO[]
	 */
	public function listActiveOfEndedSessions( string $nowUtc, int $limit = 200 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT r.* FROM %i r
				 INNER JOIN " . new \Inc\Repositories\WPDBRepositories\ExamSessionRepository()->table() . " s ON r.session_id = s.id
				 WHERE r.active_slot = 1 AND s.planned_end_at <= %s
				 ORDER BY r.id ASC LIMIT %d",
				$this->table,
				$nowUtc,
				$limit
			),
			ARRAY_A
		);
		return array_map( [ ExamRegistrationDTO::class, 'fromArray' ], $rows ?: [] );
	}
}
