<?php

declare( strict_types=1 );

namespace Inc\DTO\Profile;

use Inc\Enums\Profile\NotificationType;

/**
 * Одна строка таблицы `fs_lms_notifications`.
 *
 * `payload` — JSON-снапшот данных для текста плитки (тема занятия, имя ученика,
 * балл…), собранных на момент события — источник для
 * {@see \Inc\Services\Profile\NotificationService::toClientArray()}.
 */
readonly class NotificationDTO {

	/**
	 * @param int              $id
	 * @param int              $recipientUserId WP user id получателя
	 * @param NotificationType $type
	 * @param ?int             $groupId
	 * @param ?string          $entityType
	 * @param ?int             $entityId
	 * @param array<string,mixed> $payload
	 * @param string           $url
	 * @param string           $createdAt
	 * @param ?string          $seenAt
	 * @param ?string          $readAt
	 */
	public function __construct(
		public int              $id,
		public int              $recipientUserId,
		public NotificationType $type,
		public ?int             $groupId,
		public ?string          $entityType,
		public ?int             $entityId,
		public array            $payload,
		public string           $url,
		public string           $createdAt,
		public ?string          $seenAt,
		public ?string          $readAt,
	) {}

	public static function fromArray( array $row ): self {
		$payload = $row['payload'] ?? null;
		$decoded = is_string( $payload ) && '' !== $payload ? json_decode( $payload, true ) : null;

		return new self(
			id             : (int) $row['id'],
			recipientUserId: (int) $row['recipient_user_id'],
			type           : NotificationType::from( (string) $row['type'] ),
			groupId        : isset( $row['group_id'] ) ? (int) $row['group_id'] : null,
			entityType     : $row['entity_type'] ?? null,
			entityId       : isset( $row['entity_id'] ) ? (int) $row['entity_id'] : null,
			payload        : is_array( $decoded ) ? $decoded : array(),
			url            : (string) ( $row['url'] ?? '' ),
			createdAt      : (string) $row['created_at'],
			seenAt         : $row['seen_at'] ?? null,
			readAt         : $row['read_at'] ?? null,
		);
	}

	public function isUnread(): bool {
		return null === $this->readAt;
	}
}
