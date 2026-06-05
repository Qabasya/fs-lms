<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\ExpelledArchiveDTO;

class ExpelledArchiveRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = $this->wpdb->prefix . 'fs_lms_expelled_archive';
	}

	public function find( int $id ): ?ExpelledArchiveDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? ExpelledArchiveDTO::fromArray( $row ) : null;
	}

	/**
	 * @return ExpelledArchiveDTO[]
	 */
	public function list( int $page = 1, int $perPage = 20 ): array {
		$offset = ( max( 1, $page ) - 1 ) * $perPage;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE restored_at IS NULL ORDER BY expelled_at DESC LIMIT %d OFFSET %d',
				$this->table,
				$perPage,
				$offset
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => ExpelledArchiveDTO::fromArray( $r ), $rows ?? [] );
	}

	public function count(): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE restored_at IS NULL',
				$this->table
			)
		);
	}

	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );

		return (int) $this->wpdb->insert_id;
	}
}
