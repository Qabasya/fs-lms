<?php

declare( strict_types=1 );

namespace Inc\DTO\Application;

use Inc\Enums\ApplicationStatus;

readonly class ApplicationDTO {

	public function __construct(
		public int               $id,
		public ?int              $studentPersonId,
		public ?int              $parentPersonId,
		public ApplicationStatus $status,
		public ?string           $joinCodeHash,
		public ?string           $joinCodeEnc,
		public ?string           $joinCodeExpiresAt,
		public ?string           $studentEmailHash,
		public ?string           $studentDataEnc,
		public ?string           $parentDataEnc,
		public ?int              $convertedRecordId,
		public ?string           $parentSubmittedIp,
		public ?string           $parentSubmittedUa,
		public ?int              $reviewedByUserId,
		public string            $createdAt,
		public string            $updatedAt,
		public ?string           $subjectKey = null,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:                (int) $row['id'],
			studentPersonId:   isset( $row['student_person_id'] ) ? (int) $row['student_person_id'] : null,
			parentPersonId:    isset( $row['parent_person_id'] ) ? (int) $row['parent_person_id'] : null,
			status:            ApplicationStatus::from( (string) $row['status'] ),
			joinCodeHash:      isset( $row['join_code_hash'] ) ? (string) $row['join_code_hash'] : null,
			joinCodeEnc:       isset( $row['join_code_enc'] ) ? (string) $row['join_code_enc'] : null,
			joinCodeExpiresAt: isset( $row['join_code_expires_at'] ) ? (string) $row['join_code_expires_at'] : null,
			studentEmailHash:  isset( $row['student_email_hash'] ) ? (string) $row['student_email_hash'] : null,
			studentDataEnc:    isset( $row['student_data_enc'] ) ? (string) $row['student_data_enc'] : null,
			parentDataEnc:     isset( $row['parent_data_enc'] ) ? (string) $row['parent_data_enc'] : null,
			convertedRecordId: isset( $row['converted_record_id'] ) ? (int) $row['converted_record_id'] : null,
			parentSubmittedIp: isset( $row['parent_submitted_ip'] ) ? (string) $row['parent_submitted_ip'] : null,
			parentSubmittedUa: isset( $row['parent_submitted_ua'] ) ? (string) $row['parent_submitted_ua'] : null,
			reviewedByUserId:  isset( $row['reviewed_by_user_id'] ) ? (int) $row['reviewed_by_user_id'] : null,
			createdAt:         (string) $row['created_at'],
			updatedAt:         (string) $row['updated_at'],
			subjectKey:        isset( $row['subject_key'] ) ? (string) $row['subject_key'] : null,
		);
	}

	public function toArray(): array {
		return array(
			'id'                  => $this->id,
			'student_person_id'   => $this->studentPersonId,
			'parent_person_id'    => $this->parentPersonId,
			'status'              => $this->status->value,
			'join_code_hash'      => $this->joinCodeHash,
			'join_code_enc'       => $this->joinCodeEnc,
			'join_code_expires_at'=> $this->joinCodeExpiresAt,
			'student_email_hash'  => $this->studentEmailHash,
			'student_data_enc'    => $this->studentDataEnc,
			'parent_data_enc'     => $this->parentDataEnc,
			'converted_record_id' => $this->convertedRecordId,
			'parent_submitted_ip' => $this->parentSubmittedIp,
			'parent_submitted_ua' => $this->parentSubmittedUa,
			'reviewed_by_user_id' => $this->reviewedByUserId,
			'created_at'          => $this->createdAt,
			'updated_at'          => $this->updatedAt,
			'subject_key'         => $this->subjectKey,
		);
	}
}
