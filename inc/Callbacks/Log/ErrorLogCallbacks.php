<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Log;

use Inc\Core\BaseController;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Log\ErrorLogWriter;
use Inc\Services\Security\RateLimitService;
use Inc\Shared\Traits\RequestContextProvider;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class ErrorLogCallbacks
 *
 * Наполнение журнала «Ошибки» из трёх источников:
 *
 * 1. хук {@see ErrorCode::HOOK} — любой `AjaxResponse::error()` / `fail()`;
 * 2. `check_ajax_referer` с провалом — истёкшая сессия (ответ `-1`, обработчик не вызывается);
 * 3. AJAX `ReportClientError` — сбой, который сервер не видел как ошибку (ответ не JSON).
 *
 * @package Inc\Callbacks\Log
 */
class ErrorLogCallbacks extends BaseController {

	use Sanitizer;
	use RequestContextProvider;

	/** Коды, которые вправе прислать браузер: остальные сервер пишет сам. */
	private const CLIENT_CODES = array( ErrorCode::Http, ErrorCode::Network );

	public function __construct(
		private readonly ErrorLogWriter   $writer,
		private readonly RateLimitService $rateLimit,
	) {
		parent::__construct();
	}

	/**
	 * Хук {@see ErrorCode::HOOK}.
	 *
	 * @param ErrorCode            $code    Код
	 * @param string               $message Текст для пользователя
	 * @param string               $ref     Номер инцидента
	 * @param array<string, mixed> $context Подробности
	 */
	public function onError( ErrorCode $code, string $message, string $ref, array $context = array() ): void {
		$this->writer->recordServer( $code, $message, $ref, $context );
	}

	/**
	 * Хук `check_ajax_referer`: провал nonce плагина = истёкшая сессия.
	 *
	 * Типы не сужаем: хук ядра, $action бывает и -1 (проверка без действия).
	 *
	 * @param mixed $action Nonce-действие
	 * @param mixed $result Результат проверки (false — провал)
	 */
	public function onNonceCheck( mixed $action, mixed $result ): void {
		if ( false !== $result || ! wp_doing_ajax() || ! is_string( $action ) || null === Nonce::tryFrom( $action ) ) {
			return;
		}

		$this->writer->recordServer(
			ErrorCode::Session,
			'Сессия устарела — обновите страницу.',
			ErrorLogWriter::newRef(),
			array( 'nonce' => $action )
		);
	}

	/**
	 * AJAX: отчёт о сбое из браузера.
	 * Params: code, message, ref, source_action, page_url, status, snippet
	 */
	public function ajaxReportClientError(): void {
		Nonce::ReportClientError->verify();

		if ( ! $this->rateLimit->allowClientErrorReport( $this->requestContext()->ip ) ) {
			$this->success();
			return;
		}

		$code = ErrorCode::fromCode( $this->sanitizeText( 'code' ) );
		$ref  = strtoupper( $this->sanitizeText( 'ref' ) );

		if ( null === $code || ! in_array( $code, self::CLIENT_CODES, true ) || ! preg_match( '/^[0-9A-F]{6}$/', $ref ) ) {
			$this->success();
			return;
		}

		$this->writer->recordClient(
			code:    $code->value,
			message: $this->sanitizeText( 'message' ),
			ref:     $ref,
			action:  $this->sanitizeKey( 'source_action' ),
			url:     esc_url_raw( $this->sanitizeText( 'page_url' ) ),
			context: array_filter( array(
				'status'  => $this->sanitizeInt( 'status' ) ?: null,
				'snippet' => mb_substr( $this->sanitizeText( 'snippet' ), 0, 300 ) ?: null,
			) ),
		);

		$this->success();
	}
}
