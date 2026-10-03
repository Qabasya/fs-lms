<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamEventDTO;
use Inc\Enums\Settings\TableName;

class ExamEventRepository {

	private \wpdb  $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::ExamEvents->prefixed();
	}

	public function insert( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function find( int $id ): ?ExamEventDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->table, $id ),
			ARRAY_A
		);
		return $row ? ExamEventDTO::fromArray( $row ) : null;
	}

	public function findBySubjectKey( string $subjectKey ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE subject_key = %s ORDER BY created_at DESC", $this->table, $subjectKey ),
			ARRAY_A
		);
		return array_map( [ ExamEventDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function findBySubjectsAndStatuses( array $subjectKeys, array $statuses ): array {
		if ( empty( $subjectKeys ) || empty( $statuses ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $subjectKeys ), '%s' ) );
		$statusPlaceholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$query = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE subject_key IN ($placeholders) AND status IN ($statusPlaceholders) ORDER BY created_at DESC",
			array_merge( [ $this->table ], $subjectKeys, $statuses )
		);

		$rows = $this->wpdb->get_results( $query, ARRAY_A );
		return array_map( [ ExamEventDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function findByIds( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE id IN ($placeholders) ORDER BY created_at DESC",
			array_merge( [ $this->table ], $ids )
		);

		$rows = $this->wpdb->get_results( $query, ARRAY_A );
		return array_map( [ ExamEventDTO::class, 'fromArray' ], $rows ?: [] );
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
	}
}
