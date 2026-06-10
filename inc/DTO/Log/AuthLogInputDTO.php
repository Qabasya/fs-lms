<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class AuthLogInputDTO {

	public function __construct(
		public ?string $loginIdentifier,
		public string  $action,
		public string  $result,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
	) {}

	public function toArray(): array {
		return array(
			'login_identifier' => $this->loginIdentifier,
			'action'           => $this->action,
			'result'           => $this->result,
			'actor_ip'         => $this->actorIp,
			'actor_ua'         => $this->actorUa,
			'created_at'       => $this->createdAt,
		);
	}
}
