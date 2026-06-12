<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class AuditLogInputDTO {

	public function __construct(
		public ?int    $actorUserId,
		public ?string $actorRole,
		public string  $action,
		public ?string $targetType,
		public ?int    $targetId,
		public ?string $detailsJson,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'actor_user_id' => $this->actorUserId,
			'actor_role'    => $this->actorRole,
			'action'        => $this->action,
			'target_type'   => $this->targetType,
			'target_id'     => $this->targetId,
			'details_json'  => $this->detailsJson,
			'actor_ip'      => $this->actorIp,
			'actor_ua'      => $this->actorUa,
			'created_at'    => $this->createdAt,
		);
	}
}
