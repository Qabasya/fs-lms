<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\DTO;

/**
 * Class AdOutboxItemDTO
 *
 * Строка outbox-очереди синхронизации с AD. **PII-free**: хранит только ссылки и статус;
 * логин/пароль/ФИО НЕ хранятся — перечитываются из заявки в момент отправки.
 *
 * @package Inc\Modules\AdSync\DTO
 */
readonly class AdOutboxItemDTO {

	public function __construct(
		public int     $id,
		public string  $event,
		public ?int    $applicationId,
		public ?int    $personId,
		public ?string $target,
		public string  $idempotencyKey,
		public string  $status,
		public int     $attempts,
		public ?string $nextAttemptAt,
		public ?string $lastError,
		public string  $createdAt,
		public ?string $sentAt,
	) {}

	public static function fromRow( object $row ): self {
		return new self(
			id:             (int) $row->id,
			event:          (string) $row->event,
			applicationId:  isset( $row->application_id ) ? (int) $row->application_id : null,
			personId:       isset( $row->person_id ) ? (int) $row->person_id : null,
			target:         $row->target ?? null,
			idempotencyKey: (string) $row->idempotency_key,
			status:         (string) $row->status,
			attempts:       (int) $row->attempts,
			nextAttemptAt:  $row->next_attempt_at ?? null,
			lastError:      $row->last_error ?? null,
			createdAt:      (string) $row->created_at,
			sentAt:         $row->sent_at ?? null,
		);
	}
}
