<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamReportDTO {

	public function __construct(
		public int     $id,
		public int     $eventId,
		public string  $title,
		public int     $ownerUserId,
		public ?int    $recipientSourceId,
		public string  $expiresAt,
		public ?string $revokedAt,
		public int     $version,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id               : (int) $row['id'],
			eventId          : (int) $row['event_id'],
			title            : (string) $row['title'],
			ownerUserId      : (int) $row['owner_user_id'],
			recipientSourceId: isset( $row['recipient_source_id'] ) ? (int) $row['recipient_source_id'] : null,
			expiresAt        : (string) $row['expires_at'],
			revokedAt        : $row['revoked_at'] ?? null,
			version          : (int) $row['version'],
			createdAt        : (string) $row['created_at'],
		);
	}
}
