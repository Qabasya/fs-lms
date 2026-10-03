<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Settings\TableName;

class ExamSessionRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::ExamSessions->prefixed();
	}

	public function insert( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?ExamSessionDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->table, $id ),
			ARRAY_A
		);
		return $row ? ExamSessionDTO::fromArray( $row ) : null;
	}

	public function findByEvent( int $eventId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE event_id = %d ORDER BY scheduled_at ASC", $this->table, $eventId ),
			ARRAY_A
		);
		return array_map( [ ExamSessionDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}
}
