<?php

declare( strict_types=1 );

namespace Inc\DTO\Enrollment;

use Inc\Enums\EnrollmentStatus;

readonly class StudentRecordDTO {

	public function __construct(
		public int            $id,
		public int            $studentPersonId,
		public int            $parentPersonId,
		public int            $groupId,
		public string         $snapshotLastName,
		public string         $snapshotFirstName,
		public ?string        $snapshotMiddleName,
		public ?string        $snapshotSchool,
		public ?string        $snapshotGrade,
		public ?string        $contractNo,
		public ?string        $contractDate,
		public ?string        $orderNo,
		public ?string        $orderDate,
		public EnrollmentStatus $status,
		public string         $enrolledAt,
		public ?int           $enrolledByUserId,
		public ?string        $expelledAt,
		public ?int           $expelledByUserId,
		public ?string        $expelReason,
		public string         $createdAt,
		public string         $updatedAt,
	) {}

	public function isActive(): bool {
		return EnrollmentStatus::Active === $this->status;
	}

	public function isExpelled(): bool {
		return EnrollmentStatus::Expelled === $this->status;
	}

	public static function fromArray( array $data ): static {
		return new static(
			id:                   (int) ( $data['id'] ?? 0 ),
			studentPersonId:      (int) ( $data['student_person_id'] ?? 0 ),
			parentPersonId:       (int) ( $data['parent_person_id'] ?? 0 ),
			groupId:              (int) ( $data['group_id'] ?? 0 ),
			snapshotLastName:     (string) ( $data['snapshot_last_name']   ?? '' ),
			snapshotFirstName:    (string) ( $data['snapshot_first_name']  ?? '' ),
			snapshotMiddleName:   isset( $data['snapshot_middle_name'] ) ? (string) $data['snapshot_middle_name'] : null,
			snapshotSchool:       isset( $data['snapshot_school'] )      ? (string) $data['snapshot_school']      : null,
			snapshotGrade:        isset( $data['snapshot_grade'] )       ? (string) $data['snapshot_grade']       : null,
			contractNo:           isset( $data['contract_no'] ) ? (string) $data['contract_no'] : null,
			contractDate:     isset( $data['contract_date'] ) ? (string) $data['contract_date'] : null,
			orderNo:          isset( $data['order_no'] ) ? (string) $data['order_no'] : null,
			orderDate:        isset( $data['order_date'] ) ? (string) $data['order_date'] : null,
			status:           EnrollmentStatus::from( (string) ( $data['status'] ?? 'active' ) ),
			enrolledAt:       (string) ( $data['enrolled_at'] ?? '' ),
			enrolledByUserId: isset( $data['enrolled_by_user_id'] ) ? (int) $data['enrolled_by_user_id'] : null,
			expelledAt:       isset( $data['expelled_at'] ) ? (string) $data['expelled_at'] : null,
			expelledByUserId: isset( $data['expelled_by_user_id'] ) ? (int) $data['expelled_by_user_id'] : null,
			expelReason:      isset( $data['expel_reason'] ) ? (string) $data['expel_reason'] : null,
			createdAt:        (string) ( $data['created_at'] ?? '' ),
			updatedAt:        (string) ( $data['updated_at'] ?? '' ),
		);
	}
}
