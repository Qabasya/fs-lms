<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\StudentRecordDTO;
use Inc\Enums\EnrollmentStatus;
use Inc\Enums\TableName;

class StudentRecordRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::StudentRecords->prefixed();
	}

	public function find( int $id ): ?StudentRecordDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);

		return $row ? StudentRecordDTO::fromArray( $row ) : null;
	}

	/** @return StudentRecordDTO[] */
	public function findByStudent( int $studentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d ORDER BY enrolled_at DESC',
				$this->table,
				$studentPersonId
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/** @return StudentRecordDTO[] */
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

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	public function findActiveByStudentFirst( int $studentPersonId ): ?StudentRecordDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE student_person_id = %d AND status = %s ORDER BY enrolled_at DESC LIMIT 1',
				$this->table,
				$studentPersonId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return $row ? StudentRecordDTO::fromArray( $row ) : null;
	}

	/** @return StudentRecordDTO[] */
	public function findActiveByGroupId( int $groupId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE group_id = %d AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$groupId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	/** @return StudentRecordDTO[] */
	public function findActiveByParent( int $parentPersonId ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE parent_person_id = %d AND status = %s ORDER BY enrolled_at DESC',
				$this->table,
				$parentPersonId,
				EnrollmentStatus::Active->value
			),
			ARRAY_A
		);

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
	}

	public function existsActive( int $studentPersonId, int $groupId ): bool {
		return 0 < (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE student_person_id = %d AND group_id = %d AND status = %s',
				$this->table,
				$studentPersonId,
				$groupId,
				EnrollmentStatus::Active->value
			)
		);
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
			'status'              => EnrollmentStatus::Expelled->value,
			'expelled_at'         => $expelledAt,
			'expelled_by_user_id' => $userId,
			'expel_reason'        => $reason,
			'updated_at'          => current_time( 'mysql', true ),
		) );
	}

	/** @return StudentRecordDTO[] */
	public function list( array $filters = array(), int $page = 1, int $perPage = 20 ): array {
		$offset = ( max( 1, $page ) - 1 ) * $perPage;
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

		return array_map( fn( array $r ) => StudentRecordDTO::fromArray( $r ), $rows ?: array() );
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

		if ( ! empty( $filters['student_person_id'] ) ) {
			$where  .= ' AND student_person_id = %d';
			$args[] = (int) $filters['student_person_id'];
		}

		return array( $where, $args );
	}
}
