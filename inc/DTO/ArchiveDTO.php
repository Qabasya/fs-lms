<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class ArchiveDTO {

	public function __construct(
		public int     $id,
		public ?int    $enrollmentId,
		public int     $studentPersonId,
		public int     $parentPersonId,
		public ?string $contractNo,
		public ?string $contractDate,
		public ?string $orderNo,
		public ?string $orderDate,
		public ?int    $groupId,
		public string  $enrolledAt,
		public ?string $expelledAt,
		public ?int    $expelledByUserId,
		public ?string $reason,
		public string  $createdAt,
	) {}

	public static function fromArray( array $data ): static {
		return new static(
			id:               (int) ( $data['id'] ?? 0 ),
			enrollmentId:     isset( $data['enrollment_id'] ) ? (int) $data['enrollment_id'] : null,
			studentPersonId:  (int) ( $data['student_person_id'] ?? 0 ),
			parentPersonId:   (int) ( $data['parent_person_id'] ?? 0 ),
			contractNo:       isset( $data['contract_no'] ) ? (string) $data['contract_no'] : null,
			contractDate:     isset( $data['contract_date'] ) ? (string) $data['contract_date'] : null,
			orderNo:          isset( $data['order_no'] ) ? (string) $data['order_no'] : null,
			orderDate:        isset( $data['order_date'] ) ? (string) $data['order_date'] : null,
			groupId:          isset( $data['group_id'] ) ? (int) $data['group_id'] : null,
			enrolledAt:       (string) ( $data['enrolled_at'] ?? '' ),
			expelledAt:       isset( $data['expelled_at'] ) ? (string) $data['expelled_at'] : null,
			expelledByUserId: isset( $data['expelled_by_user_id'] ) ? (int) $data['expelled_by_user_id'] : null,
			reason:           isset( $data['reason'] ) ? (string) $data['reason'] : null,
			createdAt:        (string) ( $data['created_at'] ?? '' ),
		);
	}

	public function isExpelled(): bool {
		return null !== $this->expelledAt;
	}
}
