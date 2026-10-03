<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamGuestSessionDTO {

	public function __construct(
		public int     $id,
		public string  $cookieHash,
		public string  $scope,
		public ?int    $sourceId,
		public ?int    $participationId,
		public ?int    $registrationId,
		public int     $generation,
		public string  $issuedAt,
		public string  $expiresAt,
		public ?string $revokedAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id             : (int) $row['id'],
			cookieHash     : (string) $row['cookie_hash'],
			scope          : (string) $row['scope'],
			sourceId       : isset( $row['source_id'] ) ? (int) $row['source_id'] : null,
			participationId: isset( $row['participation_id'] ) ? (int) $row['participation_id'] : null,
			registrationId : isset( $row['registration_id'] ) ? (int) $row['registration_id'] : null,
			generation     : (int) $row['generation'],
			issuedAt       : (string) $row['issued_at'],
			expiresAt      : (string) $row['expires_at'],
			revokedAt      : $row['revoked_at'] ?? null,
		);
	}
}
