<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamParticipantDTO {

	public function __construct(
		public int     $id,
		public ?int    $personId,
		public ?string $nameEnc,
		public ?string $phoneEnc,
		public ?string $messengerEnc,
		public ?string $nameHash,
		public ?string $phoneHash,
		public ?string $schoolName,
		public ?string $schoolKey,
		public ?int    $grade,
		public ?string $anonymizedAt,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id            : (int) $row['id'],
			personId      : isset( $row['person_id'] ) ? (int) $row['person_id'] : null,
			nameEnc       : $row['name_enc'] ?? null,
			phoneEnc      : $row['phone_enc'] ?? null,
			messengerEnc  : $row['messenger_enc'] ?? null,
			nameHash      : $row['name_hash'] ?? null,
			phoneHash     : $row['phone_hash'] ?? null,
			schoolName    : $row['school_name'] ?? null,
			schoolKey     : $row['school_key'] ?? null,
			grade         : isset( $row['grade'] ) ? (int) $row['grade'] : null,
			anonymizedAt  : $row['anonymized_at'] ?? null,
			createdAt     : (string) $row['created_at'],
			updatedAt     : (string) $row['updated_at'],
		);
	}
}
