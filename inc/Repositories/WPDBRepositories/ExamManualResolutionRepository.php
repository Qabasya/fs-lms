<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamManualResolutionDTO;
use Inc\Enums\Settings\TableName;

/** Журнал ручных разборов оплат: строки не меняются и не удаляются. */
class ExamManualResolutionRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamManualResolutions->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamManualResolutionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamManualResolutionDTO::fromArray( $row ) : null;
	}

	/**
	 * Разборы по заявке, свежие первыми.
	 *
	 * @return ExamManualResolutionDTO[]
	 */
	public function findByApplication( int $applicationId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE application_id = %d ORDER BY created_at DESC', $this->table, $applicationId ) );
		return array_map( array( ExamManualResolutionDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Разборы по участию, свежие первыми.
	 *
	 * @return ExamManualResolutionDTO[]
	 */
	public function findByParticipation( int $participationId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE participation_id = %d ORDER BY created_at DESC', $this->table, $participationId ) );
		return array_map( array( ExamManualResolutionDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Урегулированные заявки: свежие разборы первыми (вкладка «Урегулированы»).
	 *
	 * @return ExamManualResolutionDTO[]
	 */
	public function listRecentForApplications( int $limit = 100 ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE application_id IS NOT NULL ORDER BY id DESC LIMIT %d', $this->table, $limit ) );
		return array_map( array( ExamManualResolutionDTO::class, 'fromArray' ), $rows );
	}
}
