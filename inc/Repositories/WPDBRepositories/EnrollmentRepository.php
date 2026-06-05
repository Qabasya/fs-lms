<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\Contracts\RepositoryInterface;
use Inc\DTO\EnrollmentDTO;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\TableName;

class EnrollmentRepository implements RepositoryInterface {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Enrollments->prefixed();
	}

	public function find( int $id ): ?EnrollmentDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? EnrollmentDTO::fromArray( $row ) : null;
	}

	/**
	 * @return EnrollmentDTO[]
	 */
	public function findByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d ORDER BY enrolled_at DESC',
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EnrollmentDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * @return EnrollmentDTO[]
	 */
	public function findActiveByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$studentPersonId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EnrollmentDTO::fromArray( $row ), $rows ?: array() );
	}

	/**
	 * @return EnrollmentDTO[]
	 */
	public function findActiveByGroupKey( string $groupKey ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_key = %s AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$groupKey,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EnrollmentDTO::fromArray( $row ), $rows ?: array() );
	}

	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function setStatus( int $id, EnrollmentStatus $status ): bool {
		return $this->update( $id, array(
			'status'     => $status->value,
			'updated_at' => current_time( 'mysql' ),
		) );
	}

	public function findBySourceApplication( int $appId ): ?EnrollmentDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE source_application_id = %d LIMIT 1',
				$this->table,
				$appId
			),
			ARRAY_A
		);

		return $row ? EnrollmentDTO::fromArray( $row ) : null;
	}

	public function existsActive( int $personId, string $groupKey ): bool {
		return 0 < (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND group_key = %s AND status = %s',
				$this->table,
				$personId,
				$groupKey,
				EnrollmentStatus::Active->value
			)
		);
	}

	/**
	 * @return EnrollmentDTO[]
	 */
	public function list( array $filters = array(), int $page = 1, int $perPage = 20 ): array {
		$offset = ( $page - 1 ) * $perPage;
		[ $where, $args ] = $this->buildWhereClause( $filters );
		$args[] = $perPage;
		$args[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i {$where} ORDER BY enrolled_at DESC LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		return array_map( fn( array $row ) => EnrollmentDTO::fromArray( $row ), $rows ?: array() );
	}

	public function count( array $filters = array() ): int {
		[ $where, $args ] = $this->buildWhereClause( $filters );

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", ...$args )
		);
	}

	private function buildWhereClause( array $filters ): array {
		$where = 'WHERE 1=1';
		$args  = array( $this->table );

		if ( ! empty( $filters['status'] ) ) {
			$status = $filters['status'];
			if ( is_array( $status ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $status ), '%s' ) );
				$where       .= " AND status IN ({$placeholders})";
				array_push( $args, ...$status );
			} else {
				$where  .= ' AND status = %s';
				$args[] = $status;
			}
		}

		return array( $where, $args );
	}
}
