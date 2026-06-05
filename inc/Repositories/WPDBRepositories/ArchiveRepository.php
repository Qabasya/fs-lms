<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\ArchiveDTO;
use Inc\Enums\TableName;

class ArchiveRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::Archive->prefixed();
	}

	public function find( int $id ): ?ArchiveDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? ArchiveDTO::fromArray( $row ) : null;
	}

	public function findByEnrollmentId( int $enrollmentId ): ?ArchiveDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE enrollment_id = %d LIMIT 1',
				$this->table,
				$enrollmentId
			),
			ARRAY_A
		);

		return $row ? ArchiveDTO::fromArray( $row ) : null;
	}

	public function findActiveByStudent( int $studentPersonId ): ?ArchiveDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND expelled_at IS NULL ORDER BY enrolled_at DESC LIMIT 1',
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);

		return $row ? ArchiveDTO::fromArray( $row ) : null;
	}

	/**
	 * @return ArchiveDTO[]
	 */
	public function findActiveByParent( int $parentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE parent_person_id = %d AND expelled_at IS NULL ORDER BY enrolled_at DESC',
				$this->table,
				$parentPersonId
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => ArchiveDTO::fromArray( $r ), $rows ?: array() );
	}

	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function setExpelled( int $id, string $expelledAt, int $userId, ?string $reason ): bool {
		return $this->update( $id, array(
			'expelled_at'         => $expelledAt,
			'expelled_by_user_id' => $userId,
			'reason'              => $reason,
		) );
	}

	/**
	 * @return ArchiveDTO[]
	 */
	public function list( array $filters = array(), int $page = 1, int $perPage = 20 ): array {
		$offset = ( max( 1, $page ) - 1 ) * $perPage;
		[ $where, $args ] = $this->buildWhereClause( $filters );
		$args[] = $perPage;
		$args[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM %i {$where} ORDER BY expelled_at DESC LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => ArchiveDTO::fromArray( $r ), $rows ?: array() );
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

		if ( isset( $filters['expelled'] ) ) {
			$where .= $filters['expelled'] ? ' AND expelled_at IS NOT NULL' : ' AND expelled_at IS NULL';
		}

		if ( ! empty( $filters['student_person_id'] ) ) {
			$where  .= ' AND student_person_id = %d';
			$args[] = (int) $filters['student_person_id'];
		}

		return array( $where, $args );
	}
}
