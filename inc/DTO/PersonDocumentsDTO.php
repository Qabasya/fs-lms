<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class PersonDocumentsDTO {

	public function __construct(
		public int     $id,
		public int     $personId,
		public ?string $emailEnc,
		public ?string $emailHash,
		public ?string $phoneEnc,
		public ?string $phoneHash,
		public ?string $docType,
		public ?string $docNumberEnc,
		public ?string $docNumberHash,
		public ?string $docIssuedByEnc,
		public ?string $docIssuedDate,
		public ?string $innEnc,
		public ?string $innHash,
		public ?string $addressEnc,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:             (int) $row['id'],
			personId:       (int) $row['person_id'],
			emailEnc:       isset( $row['email_enc'] ) ? (string) $row['email_enc'] : null,
			emailHash:      isset( $row['email_hash'] ) ? (string) $row['email_hash'] : null,
			phoneEnc:       isset( $row['phone_enc'] ) ? (string) $row['phone_enc'] : null,
			phoneHash:      isset( $row['phone_hash'] ) ? (string) $row['phone_hash'] : null,
			docType:        isset( $row['doc_type'] ) ? (string) $row['doc_type'] : null,
			docNumberEnc:   isset( $row['doc_number_enc'] ) ? (string) $row['doc_number_enc'] : null,
			docNumberHash:  isset( $row['doc_number_hash'] ) ? (string) $row['doc_number_hash'] : null,
			docIssuedByEnc: isset( $row['doc_issued_by_enc'] ) ? (string) $row['doc_issued_by_enc'] : null,
			docIssuedDate:  isset( $row['doc_issued_date'] ) ? (string) $row['doc_issued_date'] : null,
			innEnc:         isset( $row['inn_enc'] ) ? (string) $row['inn_enc'] : null,
			innHash:        isset( $row['inn_hash'] ) ? (string) $row['inn_hash'] : null,
			addressEnc:     isset( $row['address_enc'] ) ? (string) $row['address_enc'] : null,
		);
	}
}