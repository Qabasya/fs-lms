<?php

declare( strict_types=1 );

namespace Inc\DTO\Exam;

readonly class ExamOperationKeyDTO {

	public function __construct(
		public int     $id,
		public string  $scope,
		public string  $operation,
		public string  $requestKey,
		public string  $payloadHash,
		public ?string $resultRef,
		public string  $expiresAt,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): self {
		return new self(
			id          : (int) $row['id'],
			scope       : (string) $row['scope'],
			operation   : (string) $row['operation'],
			requestKey  : (string) $row['request_key'],
			payloadHash : (string) $row['payload_hash'],
			resultRef   : $row['result_ref'] ?? null,
			expiresAt   : (string) $row['expires_at'],
			createdAt   : (string) $row['created_at'],
		);
	}
}
