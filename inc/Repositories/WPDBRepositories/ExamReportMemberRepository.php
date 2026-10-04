<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamReportMemberDTO;
use Inc\Enums\Settings\TableName;

/** Участники школьного отчёта. */
class ExamReportMemberRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamReportMembers->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamReportMemberDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamReportMemberDTO::fromArray( $row ) : null;
	}

	/**
	 * Участники отчёта.
	 *
	 * @return ExamReportMemberDTO[]
	 */
	public function findByReport( int $reportId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE report_id = %d', $this->table, $reportId ) );
		return array_map( array( ExamReportMemberDTO::class, 'fromArray' ), $rows );
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
