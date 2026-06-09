<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

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
	 * Отправляет ошибку и записывает её в лог (только при WP_DEBUG).
	 */
	protected function error( string $message, array $context = array() ): void {
		PluginLogger::debug( get_class( $this ), $message, $context );

		if ( ! empty( $context ) ) {
			wp_send_json_error( array_merge( array( 'message' => $message ), $context ) );
		} else {
			wp_send_json_error( $message );
		}
	}

	/**
	 * Быстрая отправка успешного ответа.
	 */
	protected function success( array $data = array() ): void {
		wp_send_json_success( $data );
	}
}
