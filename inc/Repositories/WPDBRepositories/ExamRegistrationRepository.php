<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamRegistrationDTO;
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
}
