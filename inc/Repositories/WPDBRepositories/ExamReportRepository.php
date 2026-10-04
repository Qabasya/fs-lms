<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamReportDTO;
use Inc\Enums\Settings\TableName;

/** Школьные отчёты по проведению. */
class ExamReportRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamReports->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamReportDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamReportDTO::fromArray( $row ) : null;
	}

	/**
	 * Действующие (не отозванные) отчёты проведения.
	 *
	 * @return ExamReportDTO[]
	 */
	public function findByEvent( int $eventId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d AND revoked_at IS NULL', $this->table, $eventId ) );
		return array_map( array( ExamReportDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @return int Число затронутых строк.
	 */
	public function update( int $id, array $data ): int {
		return $this->updateRow( $this->table, $data, array( 'id' => $id ) );
	}
}
