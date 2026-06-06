<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Enums\TableName;

class GroupsRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Groups->prefixed();
	}

	public function findById( int $id ): ?object {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d LIMIT 1',
				$this->table,
				$id
			)
		);

		return $row ?: null;
	}

	public function findByGroupId( string $groupId ): ?object {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %s LIMIT 1',
				$this->table,
				$groupId
			)
		);

		return $row ?: null;
	}

	public function findBySubjectKey( string $subjectKey ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE subject_key = %s ORDER BY name ASC',
				$this->table,
				$subjectKey
			)
		) ?: array();
	}

	public function findByPeriodAndSubject( string $periodId, string $subjectKey ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE academic_period_id = %s AND subject_key = %s ORDER BY name ASC',
				$this->table,
				$periodId,
				$subjectKey
			)
		) ?: array();
	}

	public function findByPeriodId( string $periodId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE academic_period_id = %s ORDER BY name ASC',
				$this->table,
				$periodId
			)
		) ?: array();
	}

	public function findAll(): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY subject_key, name ASC', $this->table )
		) ?: array();
	}

	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'id' => $id ) );
	}
}
