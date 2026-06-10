<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

readonly class AuthLogDTO {

	public function __construct(
		public int     $id,
		public ?string $loginIdentifier,
		public string  $action,
		public string  $result,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
	) {}

	public static function fromArray( array $row ): static {
		return new static(
			id:              (int) $row['id'],
			loginIdentifier: isset( $row['login_identifier'] ) ? (string) $row['login_identifier'] : null,
			action:          (string) $row['action'],
			result:          (string) $row['result'],
			actorIp:         (string) $row['actor_ip'],
			actorUa:         isset( $row['actor_ua'] ) ? (string) $row['actor_ua'] : null,
			createdAt:       (string) $row['created_at'],
		);
	}
}
