<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class ExpelledArchiveDTO {

	public function __construct(
		public int     $id,
		public ?int    $enrollmentId,
		public ?int    $studentPersonId,
		public ?int    $parentPersonId,
		public string  $dataEnc,
		public string  $expelledAt,
		public ?int    $expelledByUserId,
		public ?string $reason,
		/** Зарезервировано для восстановления — не реализовано. */
		public ?string $restoredAt,
		/** Зарезервировано для восстановления — не реализовано. */
		public ?int    $restoredByUserId,
		public string  $createdAt,
	) {}

	public static function fromArray( array $data ): self {
		return new self(
			id:                (int) ( $data['id'] ?? 0 ),
			enrollmentId:      isset( $data['enrollment_id'] ) ? (int) $data['enrollment_id'] : null,
			studentPersonId:   isset( $data['student_person_id'] ) ? (int) $data['student_person_id'] : null,
			parentPersonId:    isset( $data['parent_person_id'] ) ? (int) $data['parent_person_id'] : null,
			dataEnc:           (string) ( $data['data_enc'] ?? '' ),
			expelledAt:        (string) ( $data['expelled_at'] ?? '' ),
			expelledByUserId:  isset( $data['expelled_by_user_id'] ) ? (int) $data['expelled_by_user_id'] : null,
			reason:            isset( $data['reason'] ) ? (string) $data['reason'] : null,
			restoredAt:        isset( $data['restored_at'] ) ? (string) $data['restored_at'] : null,
			restoredByUserId:  isset( $data['restored_by_user_id'] ) ? (int) $data['restored_by_user_id'] : null,
			createdAt:         (string) ( $data['created_at'] ?? '' ),
		);
	}

	public function isRestored(): bool {
		return null !== $this->restoredAt;
	}
}
