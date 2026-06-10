<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class DataChangeLogInputDTO {

	public function __construct(
		public int     $actorUserId,
		public ?string $actorRole,
		public int     $targetPersonId,
		public string  $fieldName,
		public ?string $oldValueEnc,
		public ?string $newValueEnc,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id'    => $this->actorUserId,
			'actor_role'       => $this->actorRole,
			'target_person_id' => $this->targetPersonId,
			'field_name'       => $this->fieldName,
			'old_value_enc'    => $this->oldValueEnc,
			'new_value_enc'    => $this->newValueEnc,
			'created_at'       => $this->createdAt,
		);
	}
}
