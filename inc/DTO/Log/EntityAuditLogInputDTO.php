<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

use Inc\Enums\EntityType;
use Inc\Enums\OperationType;

/**
 * Input DTO для записи строки в entity_audit_log.
 */
readonly class EntityAuditLogInputDTO {

	public function __construct(
		public ?int          $actorUserId,
		public ?string       $actorRole,
		public OperationType $operation,
		public EntityType    $entityType,
		public ?int          $entityId,
		public ?string       $oldLabel,
		public string        $actorIp,
		public string        $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id' => $this->actorUserId,
			'actor_role'    => $this->actorRole,
			'operation'     => $this->operation->value,
			'entity_type'   => $this->entityType->value,
			'entity_id'     => $this->entityId,
			'old_label'     => $this->oldLabel,
			'actor_ip'      => $this->actorIp,
			'created_at'    => $this->createdAt,
		);
	}
}
