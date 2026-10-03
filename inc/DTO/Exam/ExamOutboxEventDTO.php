<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamOutboxEventDTO {

	public function __construct(
		public int     $id,
		public string  $eventUuid,
		public string  $type,
		public string  $aggregateType,
		public int     $aggregateId,
		public int     $aggregateVersion,
		public ?string $payload,
		public string  $availableAt,
		public ?string $processedAt,
		public int     $attempts,
		public ?string $leasedUntil,
		public ?string $lastError,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id               : (int) $row['id'],
			eventUuid        : (string) $row['event_uuid'],
			type             : (string) $row['type'],
			aggregateType    : (string) $row['aggregate_type'],
			aggregateId      : (int) $row['aggregate_id'],
			aggregateVersion : (int) $row['aggregate_version'],
			payload          : $row['payload'] ?? null,
			availableAt      : (string) $row['available_at'],
			processedAt      : $row['processed_at'] ?? null,
			attempts         : (int) $row['attempts'],
			leasedUntil      : $row['leased_until'] ?? null,
			lastError        : $row['last_error'] ?? null,
			createdAt        : (string) $row['created_at'],
		);
	}
}
