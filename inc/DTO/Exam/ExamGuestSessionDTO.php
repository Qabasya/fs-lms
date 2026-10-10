<?php
/**
 * Сеанс гостевого доступа: хеш секретного ключа и ограниченная область действия, без открытого токена.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\DTO\Exam;

/** Сеанс гостевого доступа: хеш секретного ключа и ограниченная область действия, без открытого токена. */
readonly class ExamGuestSessionDTO {

	/** Принимает типизированные поля без обращения к хранилищу. */
	public function __construct(
		public int $id,
		public string $cookieHash,
		public string $scope,
		public ?int $sourceId,
		public ?int $participationId,
		public ?int $registrationId,
		public ?int $reportId,
		public int $generation,
		public string $issuedAt,
		public string $expiresAt,
		public ?string $revokedAt,
	) {}

	/** Восстанавливает типы полей из строки репозитория. */
	public static function fromArray( array $row ): self {
		return new self(
			id             : (int) $row['id'],
			cookieHash     : (string) $row['cookie_hash'],
			scope          : (string) $row['scope'],
			sourceId       : isset( $row['source_id'] ) ? (int) $row['source_id'] : null,
			participationId: isset( $row['participation_id'] ) ? (int) $row['participation_id'] : null,
			registrationId : isset( $row['registration_id'] ) ? (int) $row['registration_id'] : null,
			reportId       : isset( $row['report_id'] ) ? (int) $row['report_id'] : null,
			generation     : (int) $row['generation'],
			issuedAt       : (string) $row['issued_at'],
			expiresAt      : (string) $row['expires_at'],
			revokedAt      : $row['revoked_at'] ?? null,
		);
	}
}
