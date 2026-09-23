<?php

declare( strict_types=1 );

namespace Inc\DTO\Log;

/**
 * Запись журнала ошибок пользователей (fs_lms_error_log).
 *
 * @package Inc\DTO\Log
 */
readonly class ErrorLogDTO {

	/**
	 * @param int                  $id        ID записи
	 * @param string               $ref       Номер инцидента (виден пользователю рядом с кодом)
	 * @param string               $code      Код ошибки ({@see \Inc\Enums\Log\ErrorCode})
	 * @param string               $message   Текст, который увидел пользователь
	 * @param string               $source    server — отказ обработчика, client — сбой, пойманный в браузере
	 * @param int|null             $userId    Пользователь WP (null — гость)
	 * @param string|null          $action    AJAX-действие / операция
	 * @param string|null          $url       Страница, на которой случилась ошибка
	 * @param array<string, mixed> $context   Подробности (занятие, работа, HTTP-статус…)
	 * @param string               $actorIp   IP
	 * @param string|null          $actorUa   User-Agent
	 * @param string               $createdAt Дата (UTC)
	 */
	public function __construct(
		public int     $id,
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

	public static function fromArray( array $row ): static {
		$context = json_decode( (string) ( $row['context'] ?? '' ), true );

		return new static(
			id:        (int) $row['id'],
			ref:       (string) $row['ref'],
			code:      (string) $row['code'],
			message:   (string) ( $row['message'] ?? '' ),
			source:    (string) ( $row['source'] ?? 'server' ),
			userId:    ! empty( $row['user_id'] ) ? (int) $row['user_id'] : null,
			action:    isset( $row['action'] ) ? (string) $row['action'] : null,
			url:       isset( $row['url'] ) ? (string) $row['url'] : null,
			context:   is_array( $context ) ? $context : array(),
			actorIp:   (string) ( $row['actor_ip'] ?? '' ),
			actorUa:   isset( $row['actor_ua'] ) ? (string) $row['actor_ua'] : null,
			createdAt: (string) $row['created_at'],
		);
	}
}
