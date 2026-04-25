<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

/**
 * Trait AjaxResponse
 * Унифицирует AJAX-ответы и обеспечивает автоматическое логирование ошибок.
 */
trait AjaxResponse {

	/**
	 * Универсальный метод: успех или ошибка на основе условия.
	 *
	 * @param mixed  $result      Результат операции (bool или данные).
	 * @param string $error_msg   Сообщение при неудаче.
	 * @param string $success_msg Сообщение при успехе (опционально).
	 * @param array  $extra_data  Дополнительные данные для передачи в JSON.
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
	 * Отправляет ошибку и записывает её в лог.
	 */
	protected function error( string $message, array $context = array() ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$class = property_exists( $this, 'class' ) ? get_class( $this ) : 'Unknown Class';
			error_log( sprintf( '[FS LMS AJAX Error] %s: %s | Context: %s', $class, $message, wp_json_encode( $context ) ) );
		}

		wp_send_json_error( $message );
	}

	/**
	 * Быстрая отправка успешного ответа.
	 */
	protected function success( array $data = array() ): void {
		wp_send_json_success( $data );
	}
}
