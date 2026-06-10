<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Log\ExportLogDTO;
use Inc\DTO\Log\ExportLogInputDTO;
use Inc\Enums\TableName;

class ExportLogRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::ExportLog->prefixed();
	}

	public function create( ExportLogInputDTO $input ): int {
		$this->wpdb->insert( $this->table, $input->toArray() );
		return (int) $this->wpdb->insert_id;
	}

	public function list( array $filters, int $page, int $perPage ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where      = implode( ' AND ', $conditions );
		$bindings[] = $perPage;
		$bindings[] = ( $page - 1 ) * $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => ExportLogDTO::fromArray( $row ), $rows ?: array() );
	}

	public function countFiltered( array $filters ): int {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $bindings )
		);
	}

	public function listAll( array $filters ): array {
		[ $conditions, $bindings ] = $this->buildConditions( $filters );
		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC", $bindings ),
			ARRAY_A
		);

		return array_map( fn( array $row ) => ExportLogDTO::fromArray( $row ), $rows ?: array() );
	}

	private function buildConditions( array $filters ): array {
		$conditions = array( '1=1' );
		$bindings   = array( $this->table );

		if ( ! empty( $filters['actor_user_id'] ) ) {
			$conditions[] = 'actor_user_id = %d';
			$bindings[]   = (int) $filters['actor_user_id'];
		}
		if ( ! empty( $filters['data_type'] ) ) {
			$conditions[] = 'data_type = %s';
			$bindings[]   = $filters['data_type'];
		}
		if ( ! empty( $filters['date_from'] ) ) {
			$conditions[] = 'created_at >= %s';
			$bindings[]   = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$conditions[] = 'created_at <= %s';
			$bindings[]   = $filters['date_to'] . ' 23:59:59';
		}

		return array( $conditions, $bindings );
	}
}
