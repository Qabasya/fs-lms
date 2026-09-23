<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Вставка записи в журнал ошибок пользователей (fs_lms_error_log).
 *
 * @package Inc\DTO\Log
 */
readonly class ErrorLogInputDTO {

	/**
	 * @param array<string, mixed> $context Подробности — хранятся JSON
	 */
	public function __construct(
		public string  $ref,
		public string  $code,
		public string  $message,
		public string  $source,
		public ?int    $userId,
		public ?string $action,
		public ?string $url,
		public array   $context,
		public string  $actorIp,
		public ?string $actorUa,
		public string  $createdAt,
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'ref'        => $this->ref,
			'code'       => $this->code,
			'message'    => mb_substr( $this->message, 0, 500 ),
			'source'     => $this->source,
			'user_id'    => $this->userId,
			'action'     => null !== $this->action ? mb_substr( $this->action, 0, 100 ) : null,
			'url'        => null !== $this->url ? mb_substr( $this->url, 0, 500 ) : null,
			'context'    => array() !== $this->context ? (string) wp_json_encode( $this->context, JSON_UNESCAPED_UNICODE ) : null,
			'actor_ip'   => $this->actorIp,
			'actor_ua'   => $this->actorUa,
			'created_at' => $this->createdAt,
		);
	}
}
