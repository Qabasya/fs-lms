<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Settings\TableName;

class ExamParticipationRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::ExamParticipations->prefixed();
	}

	public function insert( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?ExamParticipationDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->table, $id ),
			ARRAY_A
		);
		return $row ? ExamParticipationDTO::fromArray( $row ) : null;
	}

	public function findByEventAndParticipant( int $eventId, int $participantId ): ?ExamParticipationDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE event_id = %d AND participant_id = %d", $this->table, $eventId, $participantId ),
			ARRAY_A
		);
		return $row ? ExamParticipationDTO::fromArray( $row ) : null;
	}

	public function findByEvent( int $eventId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE event_id = %d", $this->table, $eventId ),
			ARRAY_A
		);
		return array_map( [ ExamParticipationDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function findByEventAndStudent( int $eventId, int $personId ): ?ExamParticipationDTO {
		// Найти участника по person_id
		$personRepo = new PersonRepository();
		$person = $personRepo->findById( $personId );
		if ( ! $person ) {
			return null;
		}

		// Найти участие по event_id и participant_id
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE event_id = %d AND participant_id = %d",
				$this->table,
				$eventId,
				$person->id
			),
			ARRAY_A
		);
		return $row ? ExamParticipationDTO::fromArray( $row ) : null;
	}

	public function findEventIdsForStudent( int $personId ): array {
		$personRepo = new PersonRepository();
		$person = $personRepo->findById( $personId );
		if ( ! $person ) {
			return [];
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT DISTINCT event_id FROM %i WHERE participant_id = %d", $this->table, $person->id ),
			ARRAY_A
		);
		return array_map( static fn( $row ) => (int) $row['event_id'], $rows ?: [] );
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}

	/**
	 * Заблокировать участие для обновления (6.1).
	 */
	public function findForUpdate( int $id ): ?ExamParticipationDTO {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d FOR UPDATE",
				$this->table,
				$id
			),
			ARRAY_A
		);
		return $row ? ExamParticipationDTO::fromArray( $row ) : null;
	}

	/**
	 * Установить текущую попытку (6.1).
	 */
	public function setCurrentAttempt( int $participationId, ?int $attemptId ): void {
		$this->wpdb->update(
			$this->table,
			[ 'current_attempt_id' => $attemptId ],
			[ 'id' => $participationId ]
		);
	}

	/**
	 * Установить активную запись (6.3).
	 */
	public function setActiveRegistration( int $participationId, ?int $registrationId ): void {
		$this->wpdb->update(
			$this->table,
			[ 'active_slot' => $registrationId ],
			[ 'id' => $participationId ]
		);
	}
}
