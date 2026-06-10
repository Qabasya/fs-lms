<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class DeletionLogInputDTO {

	public function __construct(
		public int     $actorUserId,
		public ?string $actorRole,
		public string  $entityType,
		public int     $entityId,
		public ?string $cascadedSummary,
		public string  $actorIp,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id'    => $this->actorUserId,
			'actor_role'       => $this->actorRole,
			'entity_type'      => $this->entityType,
			'entity_id'        => $this->entityId,
			'cascaded_summary' => $this->cascadedSummary,
			'actor_ip'         => $this->actorIp,
			'created_at'       => $this->createdAt,
		);
	}
}
