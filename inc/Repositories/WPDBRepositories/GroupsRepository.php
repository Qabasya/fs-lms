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
				'SELECT * FROM %i WHERE group_id = %d LIMIT 1',
				$this->table,
				$id
			)
		);

		return $row ?: null;
	}

	public function findBySubjectId( string $subjectId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE subject_id = %s ORDER BY group_name ASC',
				$this->table,
				$subjectId
			)
		) ?: array();
	}

	public function findByPeriodAndSubject( string $periodId, string $subjectId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE period_id = %s AND subject_id = %s ORDER BY group_name ASC',
				$this->table,
				$periodId,
				$subjectId
			)
		) ?: array();
	}

	public function findByPeriodId( string $periodId ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE period_id = %s ORDER BY group_name ASC',
				$this->table,
				$periodId
			)
		) ?: array();
	}

	public function findAll(): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM %i ORDER BY subject_id, group_name ASC', $this->table )
		) ?: array();
	}

	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'group_id' => $id ) );
	}

	public function delete( int $id ): bool {
		return false !== $this->wpdb->delete( $this->table, array( 'group_id' => $id ) );
	}
}
