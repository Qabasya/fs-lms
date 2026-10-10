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

	/** @return bool true — участие добавлено; false — оно уже в отчёте (уникальный индекс). */
	public function add( int $reportId, int $participationId, ?int $consentRef, string $nowUtc ): bool {
		if ( null === $consentRef ) {
			$sql = $this->wpdb->prepare( 'INSERT IGNORE INTO %i ( report_id, participation_id, created_at ) VALUES ( %d, %d, %s )', $this->table, $reportId, $participationId, $nowUtc );
		} else {
			$sql = $this->wpdb->prepare( 'INSERT IGNORE INTO %i ( report_id, participation_id, consent_ref, created_at ) VALUES ( %d, %d, %d, %s )', $this->table, $reportId, $participationId, $consentRef, $nowUtc );
		}

		return 1 === $this->write( $sql );
	}

	public function remove( int $reportId, int $participationId ): bool {
		return 1 === $this->write( $this->wpdb->prepare( 'DELETE FROM %i WHERE report_id = %d AND participation_id = %d', $this->table, $reportId, $participationId ) );
	}

	public function isMember( int $reportId, int $participationId ): bool {
		return 0 !== $this->readInt( $this->wpdb->prepare( 'SELECT id FROM %i WHERE report_id = %d AND participation_id = %d', $this->table, $reportId, $participationId ) );
	}

	/**
	 * Участия отчёта в порядке включения: номер строки в отчёте (`p=`) — порядковый номер из этого списка.
	 *
	 * @return int[]
	 */
	public function listParticipationIds( int $reportId ): array {
		return $this->readInts( $this->wpdb->prepare( 'SELECT participation_id FROM %i WHERE report_id = %d ORDER BY id ASC', $this->table, $reportId ) );
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
