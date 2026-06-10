<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class ExportLogInputDTO {

	public function __construct(
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $dataType,
		public string  $actionType,
		public ?string $targetIdsJson,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id'  => $this->actorUserId,
			'actor_role'     => $this->actorRole,
			'data_type'      => $this->dataType,
			'action_type'    => $this->actionType,
			'target_ids_json' => $this->targetIdsJson,
			'created_at'     => $this->createdAt,
		);
	}
}
