<?php
/**
 * Снимок заявки и брони до подтверждения записи; персональные данные остаются зашифрованными.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\DTO\Exam;

/** Снимок заявки и брони до подтверждения записи; персональные данные остаются зашифрованными. */
readonly class ExamGuestApplicationDTO {

	/** Принимает типизированные поля без обращения к хранилищу. */
	public function __construct(
		public int $id,
		public int $eventId,
		public int $sessionId,
		public int $sourceId,
		public ?int $participantId,
		public string $identityHash,
		public ?int $activeSlot,
		public string $state,
		public bool $isHeld,
		public ?string $holdExpiresAt,
		public string $requestKey,
		public ?string $draftEnc,
		public ?string $sourceSnapshot,
		public ?string $consentRefs,
		public ?int $participationId,
		public ?int $registrationId,
		public ?string $ipHash,
		public ?int $createdByUserId,
		public int $version,
		public string $createdAt,
		public string $updatedAt,
	) {}

	/** Восстанавливает типы полей из строки репозитория. */
	public static function fromArray( array $row ): self {
		return new self(
			id              : (int) $row['id'],
			eventId         : (int) $row['event_id'],
			sessionId       : (int) $row['session_id'],
			sourceId        : (int) $row['source_id'],
			participantId   : isset( $row['participant_id'] ) ? (int) $row['participant_id'] : null,
			identityHash    : (string) $row['identity_hash'],
			activeSlot      : isset( $row['active_slot'] ) ? (int) $row['active_slot'] : null,
			state           : (string) $row['state'],
			isHeld          : (bool) $row['is_held'],
			holdExpiresAt   : $row['hold_expires_at'] ?? null,
			requestKey      : (string) $row['request_key'],
			draftEnc        : $row['draft_enc'] ?? null,
			sourceSnapshot  : $row['source_snapshot'] ?? null,
			consentRefs     : $row['consent_refs'] ?? null,
			participationId : isset( $row['participation_id'] ) ? (int) $row['participation_id'] : null,
			registrationId  : isset( $row['registration_id'] ) ? (int) $row['registration_id'] : null,
			ipHash          : $row['ip_hash'] ?? null,
			createdByUserId : isset( $row['created_by_user_id'] ) ? (int) $row['created_by_user_id'] : null,
			version         : (int) $row['version'],
			createdAt       : (string) $row['created_at'],
			updatedAt       : (string) $row['updated_at'],
		);
	}
}
