<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\Exam\ExamGuestSessionDTO;
use Inc\Enums\Settings\TableName;

/** Сессии гостя по кукам: в базе только хеш куки. */
class ExamGuestSessionRepository extends AbstractExamRepository {

	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		parent::__construct( $wpdb );
		$this->table = TableName::ExamGuestSessions->prefixed();
	}

	/** @param array<string, mixed> $data */
	public function insert( array $data ): int {
		return $this->insertRow( $this->table, $data );
	}

	public function find( int $id ): ?ExamGuestSessionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ) );
		return null !== $row ? ExamGuestSessionDTO::fromArray( $row ) : null;
	}

	/** Действующая (не отозванная) сессия по хешу куки. */
	public function findByCookieHash( string $cookieHash ): ?ExamGuestSessionDTO {
		$row = $this->readRow( $this->wpdb->prepare( 'SELECT * FROM %i WHERE cookie_hash = %s AND revoked_at IS NULL', $this->table, $cookieHash ) );
		return null !== $row ? ExamGuestSessionDTO::fromArray( $row ) : null;
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
