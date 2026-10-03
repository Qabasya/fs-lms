<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamAccessTokenDTO {

	public function __construct(
		public int     $id,
		public string  $purpose,
		public int     $targetId,
		public string  $tokenHash,
		public int     $generation,
		public ?string $expiresAt,
		public ?string $revokedAt,
		public ?string $consumedAt,
		public int     $issuerUserId,
		public ?string $passedAt,
		public ?int    $passedByUserId,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id            : (int) $row['id'],
			purpose       : (string) $row['purpose'],
			targetId      : (int) $row['target_id'],
			tokenHash     : (string) $row['token_hash'],
			generation    : (int) $row['generation'],
			expiresAt     : $row['expires_at'] ?? null,
			revokedAt     : $row['revoked_at'] ?? null,
			consumedAt    : $row['consumed_at'] ?? null,
			issuerUserId  : (int) $row['issuer_user_id'],
			passedAt      : $row['passed_at'] ?? null,
			passedByUserId: isset( $row['passed_by_user_id'] ) ? (int) $row['passed_by_user_id'] : null,
			createdAt     : (string) $row['created_at'],
		);
	}
}
