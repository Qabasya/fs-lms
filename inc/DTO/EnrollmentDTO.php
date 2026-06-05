<?php

declare( strict_types=1 );

namespace Inc\DTO;

use Inc\Enums\EnrollmentStatus;

readonly class EnrollmentDTO {

	public function __construct(
		public int             $id,
		public int             $studentPersonId,
		public ?int            $sourceApplicationId,
		public ?string         $groupKey,
		public EnrollmentStatus $status,
		public string          $enrolledAt,
		public ?string         $terminatedAt,
		public ?string         $terminatedReason,
		public ?int            $terminatedByUserId,
		public ?string         $snapshotEnc,
		public string          $createdAt,
		public string          $updatedAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:                  (int) $row['id'],
			studentPersonId:     (int) $row['student_person_id'],
			sourceApplicationId: isset( $row['source_application_id'] ) ? (int) $row['source_application_id'] : null,
			groupKey:            isset( $row['group_key'] ) && $row['group_key'] !== '' ? (string) $row['group_key'] : null,
			status:              EnrollmentStatus::from( (string) $row['status'] ),
			enrolledAt:          (string) $row['enrolled_at'],
			terminatedAt:        isset( $row['terminated_at'] ) ? (string) $row['terminated_at'] : null,
			terminatedReason:    isset( $row['terminated_reason'] ) ? (string) $row['terminated_reason'] : null,
			terminatedByUserId:  isset( $row['terminated_by_user_id'] ) ? (int) $row['terminated_by_user_id'] : null,
			snapshotEnc:         isset( $row['snapshot_enc'] ) ? (string) $row['snapshot_enc'] : null,
			createdAt:           (string) $row['created_at'],
			updatedAt:           (string) $row['updated_at'],
		);
	}

	public function toArray(): array {
		return array(
			'id'                    => $this->id,
			'student_person_id'     => $this->studentPersonId,
			'source_application_id' => $this->sourceApplicationId,
			'group_key'             => $this->groupKey,
			'status'                => $this->status->value,
			'enrolled_at'           => $this->enrolledAt,
			'terminated_at'         => $this->terminatedAt,
			'terminated_reason'     => $this->terminatedReason,
			'terminated_by_user_id' => $this->terminatedByUserId,
			'snapshot_enc'          => $this->snapshotEnc,
			'created_at'            => $this->createdAt,
			'updated_at'            => $this->updatedAt,
		);
	}
}
