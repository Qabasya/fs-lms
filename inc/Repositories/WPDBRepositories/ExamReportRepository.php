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
	 * Все отчёты проведения, в том числе отозванные: сотруднику виден список целиком, состояние решает сервис.
	 *
	 * @return ExamReportDTO[]
	 */
	public function listByEvent( int $eventId ): array {
		$rows = $this->readRows( $this->wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %d ORDER BY id DESC', $this->table, $eventId ) );
		return array_map( array( ExamReportDTO::class, 'fromArray' ), $rows );
	}

	/**
	 * Меняет отзыв отчёта и поднимает версию: условный `UPDATE` по версии, которую видела вкладка.
	 *
	 * @return bool true — ровно одна строка; false — версия устарела.
	 */
	public function setRevoked( int $id, ?string $revokedAtUtc, int $expectedVersion ): bool {
		if ( null === $revokedAtUtc ) {
			$sql = $this->wpdb->prepare( 'UPDATE %i SET revoked_at = NULL, version = version + 1 WHERE id = %d AND version = %d', $this->table, $id, $expectedVersion );
		} else {
			$sql = $this->wpdb->prepare( 'UPDATE %i SET revoked_at = %s, version = version + 1 WHERE id = %d AND version = %d', $this->table, $revokedAtUtc, $id, $expectedVersion );
		}

		return 1 === $this->write( $sql );
	}

	/** Поднимает версию отчёта (изменился состав). @return bool false — версия устарела. */
	public function bumpVersion( int $id, int $expectedVersion ): bool {
		return 1 === $this->write( $this->wpdb->prepare( 'UPDATE %i SET version = version + 1 WHERE id = %d AND version = %d', $this->table, $id, $expectedVersion ) );
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
