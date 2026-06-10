<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class EmailLogInputDTO {

	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $emailType,
		public ?int    $targetPersonId,
		public string  $status,
		public ?string $errorMessage,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id'    => $this->actorUserId,
			'actor_role'       => $this->actorRole,
			'email_type'       => $this->emailType,
			'target_person_id' => $this->targetPersonId,
			'status'           => $this->status,
			'error_message'    => $this->errorMessage,
			'created_at'       => $this->createdAt,
		);
	}
}
