<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\PersonDTO;
use Inc\Enums\TableName;

class PersonRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Persons->prefixed();
	}

	public function find( int $id ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL LIMIT 1',
				$this->table,
				$id
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}

	public function findByWpUserId( int $wpUserId ): ?PersonDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE wp_user_id = %d AND deleted_at IS NULL LIMIT 1',
				$this->table,
				$wpUserId
			),
			ARRAY_A
		);

		return $row ? PersonDTO::fromArray( $row ) : null;
	}


	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		return $this->softDelete( $id );
	}

	public function softDelete( int $id ): bool {
		return $this->update( $id, array( 'deleted_at' => current_time( 'mysql', true ) ) );
	}

	public function setWpUser( int $id, int $wpUserId ): bool {
		return $this->update( $id, array( 'wp_user_id' => $wpUserId ) );
	}

	/** @return PersonDTO[] */
	public function findByIsStudent( bool $isStudent ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE is_student = %d AND deleted_at IS NULL ORDER BY last_name, first_name',
				$this->table,
				$isStudent ? 1 : 0
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PersonDTO::fromArray( $row ), $rows ?: array() );
	}

	public function findDeletedOlderThan( int $days ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$this->table,
				$days
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => PersonDTO::fromArray( $row ), $rows ?: array() );
	}
}
