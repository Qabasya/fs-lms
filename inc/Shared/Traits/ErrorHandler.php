<?php

namespace Inc\Shared\Traits;

use Inc\Enums\Capability;
use Inc\Shared\PluginLogger;

/**
 * Dual-context error dispatch: AJAX → wp_send_json_error, HTTP → wp_die.
 * Используется только в контроллерах, которые обрабатывают оба типа запросов (AuthController).
 */
trait ErrorHandler {

	/**
	 * @return never
	 */
	protected function sendError(
		string $code,
		string $message,
		int $status = 400,
		?Capability $required_capability = null
	): void {
		if ( $required_capability && ! current_user_can( $required_capability->value ) ) {
			$status  = 403;
			$message = 'Доступ запрещён';
		}

		PluginLogger::debug( $code, $message, array( 'status' => $status, 'user_id' => get_current_user_id() ) );

		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'code' => $code, 'message' => $message ), $status );
		}

		wp_die( esc_html( $message ), 'LMS Error', array( 'response' => $status, 'back_link' => true ) );
	}
}
