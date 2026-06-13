<?php

declare( strict_types=1 );

namespace Inc\DTO\Enrollment;

readonly class StudentRecordInputDTO {

	public function __construct(
		public int     $studentPersonId,
		public int     $parentPersonId,
		public string  $status,
		public string  $enrolledAt,
		public string  $createdAt,
		public string  $updatedAt,
		public ?int    $groupId             = null,
		public ?string $snapshotLastName    = null,
		public ?string $snapshotFirstName   = null,
		public ?string $snapshotMiddleName  = null,
		public ?string $snapshotSchool      = null,
		public ?string $snapshotGrade       = null,
		public ?string $contractNo          = null,
		public ?string $contractDate        = null,
		public ?string $orderNo             = null,
		public ?string $orderDate           = null,
		public ?int    $enrolledByUserId    = null,
	) {}

	public function toArray(): array {
		return array(
			'student_person_id'    => $this->studentPersonId,
			'parent_person_id'     => $this->parentPersonId,
			'group_id'             => $this->groupId,
			'snapshot_last_name'   => $this->snapshotLastName,
			'snapshot_first_name'  => $this->snapshotFirstName,
			'snapshot_middle_name' => $this->snapshotMiddleName,
			'snapshot_school'      => $this->snapshotSchool,
			'snapshot_grade'       => $this->snapshotGrade,
			'contract_no'          => $this->contractNo,
			'contract_date'        => $this->contractDate,
			'order_no'             => $this->orderNo,
			'order_date'           => $this->orderDate,
			'status'               => $this->status,
			'enrolled_at'          => $this->enrolledAt,
			'enrolled_by_user_id'  => $this->enrolledByUserId,
			'created_at'           => $this->createdAt,
			'updated_at'           => $this->updatedAt,
		);
	}
}
