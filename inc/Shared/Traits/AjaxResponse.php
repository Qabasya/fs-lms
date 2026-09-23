<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

use Inc\Enums\Log\ErrorCode;
use Inc\Shared\PluginLogger;

trait AjaxResponse {

	/**
	 * Универсальный метод: успех или ошибка на основе условия.
	 */
	protected function respond(
		mixed $result,
		string $error_msg = 'Произошла ошибка',
		string $success_msg = '',
		array $extra_data = array()
	): void {
		if ( ! $result ) {
			$this->error( $error_msg );
		}

		$response = $extra_data;

		if ( $success_msg ) {
			$response['message'] = $success_msg;
		}

		if ( is_array( $result ) ) {
			$response = array_merge( $response, $result );
		}

		wp_send_json_success( $response );
	}

	/**
	 * Отправляет ошибку и пишет её в журнал «Ошибки» (кодом E-AJAX).
	 *
	 * Формат ответа прежний — строка или объект с context: на него завязан
	 * админский JS. Код и номер инцидента пользователю отдаёт {@see self::fail()}.
	 */
	protected function error( string $message, array $context = array() ): void {
		PluginLogger::debug( get_class( $this ), $message, $context );
		$this->reportError( ErrorCode::Ajax, $message, $context );

		if ( ! empty( $context ) ) {
			wp_send_json_error( array_merge( array( 'message' => $message ), $context ) );
		} else {
			wp_send_json_error( $message );
		}
	}

	/**
	 * Ошибка с кодом: `{message, code, ref}`. Код и номер инцидента клиент
	 * показывает пользователю — по скриншоту запись находится в журнале «Ошибки».
	 *
	 * @param ErrorCode            $code    Код ошибки
	 * @param string               $message Текст для пользователя
	 * @param array<string, mixed> $context Подробности для журнала (в ответ не уходят)
	 */
	protected function fail( ErrorCode $code, string $message, array $context = array() ): void {
		$ref = $this->reportError( $code, $message, $context );

		wp_send_json_error( array(
			'message' => $message,
			'code'    => $code->value,
			'ref'     => $ref,
		) );
	}

	/**
	 * Быстрая отправка успешного ответа.
	 */
	protected function success( array $data = array() ): void {
		wp_send_json_success( $data );
	}

	/**
	 * Номер инцидента + хук для журнала «Ошибки» (подписчик — ErrorLogController).
	 * Трейт не знает о репозиториях: запись делает подписчик.
	 *
	 * @return string Номер инцидента (6 hex-символов)
	 */
	private function reportError( ErrorCode $code, string $message, array $context ): string {
		$ref = strtoupper( bin2hex( random_bytes( 3 ) ) );

		do_action( ErrorCode::HOOK, $code, $message, $ref, $context );

		return $ref;
	}
}
