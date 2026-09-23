<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Log\ErrorLogInputDTO;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\Log\ErrorLogRepository;
use Inc\Shared\PluginLogger;
use Inc\Shared\Traits\RequestContextProvider;

/**
 * Class ErrorLogWriter
 *
 * Запись ошибок пользователей в журнал «Ошибки».
 *
 * @package Inc\Services\Log
 *
 * ### Источники
 *
 * - server — отказ AJAX-обработчика (`AjaxResponse::error()` / `fail()`) и истёкший nonce;
 * - client — то, чего сервер не видел: ответ не JSON (фатальная ошибка, 5xx), сбой в браузере.
 *   Шлёт плеер через `AjaxHook::ReportClientError`.
 *
 * Каждая запись дублируется в PHP-лог (`PluginLogger::warning`) — grep по номеру инцидента
 * работает, даже если таблица недоступна.
 */
class ErrorLogWriter {

	use RequestContextProvider;

	public function __construct(
		private readonly ErrorLogRepository $repository,
		private readonly ClockInterface     $clock,
	) {}

	/**
	 * Ошибка, отданная сервером.
	 *
	 * @param ErrorCode            $code    Код
	 * @param string               $message Текст для пользователя
	 * @param string               $ref     Номер инцидента
	 * @param array<string, mixed> $context Подробности от обработчика
	 */
	public function recordServer( ErrorCode $code, string $message, string $ref, array $context = array() ): void {
		$this->write(
			ref:     $ref,
			code:    $code->value,
			message: $message,
			source:  'server',
			action:  $this->requestAction(),
			url:     (string) wp_get_referer(),
			context: $context,
		);
	}

	/**
	 * Ошибка, пойманная в браузере (сервер её не видел или ответил не JSON).
	 *
	 * @param array<string, mixed> $context Подробности от клиента (HTTP-статус, начало ответа…)
	 */
	public function recordClient( string $code, string $message, string $ref, string $action, string $url, array $context ): void {
		$this->write(
			ref:     $ref,
			code:    $code,
			message: $message,
			source:  'client',
			action:  $action,
			url:     $url,
			context: $context,
		);
	}

	/**
	 * Номер инцидента для записи, у которой его ещё нет (истёкший nonce).
	 */
	public static function newRef(): string {
		return strtoupper( bin2hex( random_bytes( 3 ) ) );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function write( string $ref, string $code, string $message, string $source, string $action, string $url, array $context ): void {
		$ctx = $this->requestContext();

		PluginLogger::warning( 'UserError', "{$code} #{$ref}: {$message}", array(
			'action' => $action,
			'source' => $source,
		) + $context );

		try {
			$this->repository->create( new ErrorLogInputDTO(
				ref:       $ref,
				code:      $code,
				message:   $message,
				source:    $source,
				userId:    $ctx->actorUserId ?: null,
				action:    '' !== $action ? $action : null,
				url:       '' !== $url ? $url : null,
				context:   $context,
				actorIp:   $ctx->ip,
				actorUa:   '' !== $ctx->userAgent ? $ctx->userAgent : null,
				createdAt: $this->clock->now( 'mysql', true ),
			) );
		} catch ( \Throwable $e ) {
			// Журнал ошибок не должен сам ронять ответ пользователю.
			PluginLogger::exception( 'ErrorLogWriter', $e, array( 'ref' => $ref ), true );
		}
	}

	/** AJAX-действие текущего запроса. */
	private function requestAction(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только для журнала
		return sanitize_key( wp_unslash( (string) ( $_REQUEST['action'] ?? '' ) ) );
	}
}
