<?php

declare( strict_types=1 );

namespace Inc\DTO\Application;

readonly class ApplicationRecordInputDTO {

	public function __construct(
		public string  $status,
		public string  $joinCodeHash,
		public string  $joinCodeEnc,
		public string  $joinCodeExpiresAt,
		public string  $studentDataEnc,
		public string  $createdAt,
		public string  $updatedAt,
		public ?string $studentEmailHash   = null,
		public ?string $parentSubmittedIp  = null,
		public ?int    $studentPersonId    = null,
		public ?string $subjectKey         = null,
	) {}

	public function toArray(): array {
		$data = array(
			'status'               => $this->status,
			'join_code_hash'       => $this->joinCodeHash,
			'join_code_enc'        => $this->joinCodeEnc,
			'join_code_expires_at' => $this->joinCodeExpiresAt,
			'student_data_enc'     => $this->studentDataEnc,
			'created_at'           => $this->createdAt,
			'updated_at'           => $this->updatedAt,
		);

		if ( null !== $this->studentEmailHash ) {
			$data['student_email_hash'] = $this->studentEmailHash;
		}
		if ( null !== $this->parentSubmittedIp ) {
			$data['parent_submitted_ip'] = $this->parentSubmittedIp;
		}
		if ( null !== $this->studentPersonId ) {
			$data['student_person_id'] = $this->studentPersonId;
		}
		if ( null !== $this->subjectKey && '' !== $this->subjectKey ) {
			$data['subject_key'] = $this->subjectKey;
		}

		return $data;
	}
}
