<?php

declare( strict_types=1 );

namespace Inc\Repositories\WPDBRepositories;

use Inc\DTO\PersonDocumentsDTO;
use Inc\Enums\TableName;

class PersonDocumentsRepository {

	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $wpdb = null ) {
		$this->wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$this->table = TableName::PersonDocuments->prefixed();
	}

	public function findByPersonId( int $personId ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE person_id = %d LIMIT 1',
				$this->table,
				$personId
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	public function findByEmailHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE email_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	public function findByPhoneHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE phone_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	public function findByDocNumberHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE doc_number_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	public function findByInnHash( string $hash ): ?PersonDocumentsDTO {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE inn_hash = %s LIMIT 1',
				$this->table,
				$hash
			),
			ARRAY_A
		);

		return $row ? PersonDocumentsDTO::fromArray( $row ) : null;
	}

	public function create( array $data ): int {
		$this->wpdb->insert( $this->table, $data );
		return (int) $this->wpdb->insert_id;
	}

	public function update( int $personId, array $data ): bool {
		return false !== $this->wpdb->update( $this->table, $data, array( 'person_id' => $personId ) );
	}

	public function anonymize( int $personId ): bool {
		return $this->update( $personId, array(
			'email_enc'         => null,
			'email_hash'        => null,
			'phone_enc'         => null,
			'phone_hash'        => null,
			'doc_number_enc'    => null,
			'doc_number_hash'   => null,
			'doc_issued_by_enc' => null,
			'inn_enc'           => null,
			'inn_hash'          => null,
			'address_enc'       => null,
		) );
	}
}
