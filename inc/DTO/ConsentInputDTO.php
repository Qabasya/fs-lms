<?php

declare( strict_types=1 );

namespace Inc\DTO;

readonly class ConsentInputDTO {

	public function __construct(
		public ?int    $applicationId,
		public string  $consentType,
		public string  $subjectRole,
		public string  $version,
		public string  $documentHash,
		public string  $ipAddress,
		public string  $userAgent,
		public string  $acceptedAt,
		public ?int    $signedForPersonId = null,
	) {}

	public function toArray(): array {
		return array(
			'application_id'       => $this->applicationId,
			'consent_type'         => $this->consentType,
			'subject_role'         => $this->subjectRole,
			'version'              => $this->version,
			'document_hash'        => $this->documentHash,
			'ip_address'           => $this->ipAddress,
			'user_agent'           => $this->userAgent,
			'accepted_at'          => $this->acceptedAt,
			'signed_for_person_id' => $this->signedForPersonId,
		);
	}
}
