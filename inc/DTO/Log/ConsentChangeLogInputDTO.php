<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class ConsentChangeLogInputDTO {

	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public ?int    $personId,
		public string  $consentType,
		public ?string $oldHash,
		public ?string $newHash,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id' => $this->actorUserId,
			'actor_role'    => $this->actorRole,
			'person_id'     => $this->personId,
			'consent_type'  => $this->consentType,
			'old_hash'      => $this->oldHash,
			'new_hash'      => $this->newHash,
			'created_at'    => $this->createdAt,
		);
	}
}
