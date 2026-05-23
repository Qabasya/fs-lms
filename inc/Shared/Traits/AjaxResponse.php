<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

/**
 * Trait AjaxResponse
 *
 * Унифицирует AJAX-ответы и обеспечивает автоматическое логирование ошибок.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Унифицированные ответы** — стандартизация JSON-ответов для AJAX-запросов.
 * 2. **Автоматическое логирование** — запись ошибок в error_log в режиме WP_DEBUG.
 * 3. **Гибкая обработка результата** — поддержка bool, массива и дополнительных данных.
 *
 * ### Архитектурная роль:
 *
 * Предоставляет методы для классов-обработчиков (коллбеков), которые работают с AJAX.
 * Использует встроенные WordPress-функции wp_send_json_success() и wp_send_json_error().
 */
trait AjaxResponse {
	
	/**
	 * Универсальный метод: успех или ошибка на основе условия.
	 *
	 * @param mixed  $result      Результат операции (bool, массив или объект данных)
	 * @param string $error_msg   Сообщение при неудаче
	 * @param string $success_msg Сообщение при успехе (опционально)
	 * @param array  $extra_data  Дополнительные данные для передачи в JSON
	 *
	 * @return void
	 */
	protected function respond(
		mixed $result,
		string $error_msg = 'Произошла ошибка',
		string $success_msg = '',
		array $extra_data = array()
	): void {
		// Если результат ложный (false, null, 0) — отправляем ошибку
		if ( ! $result ) {
			$this->error( $error_msg );
		}
		
		$response = $extra_data;
		
		if ( $success_msg ) {
			$response['message'] = $success_msg;
		}
		
		// Если результат — массив, объединяем его с ответом
		if ( is_array( $result ) ) {
			$response = array_merge( $response, $result );
		}
		
		// wp_send_json_success() — отправляет JSON-ответ с ключом 'success' => true
		wp_send_json_success( $response );
	}
	
	/**
	 * Отправляет ошибку и записывает её в лог.
	 *
	 * @param string $message Текст ошибки
	 * @param array  $context Дополнительный контекст для логирования
	 *
	 * @return void
	 */
	protected function error( string $message, array $context = array() ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[FS LMS] %s: %s | Context: %s',
				get_class( $this ),
				$message,
				wp_json_encode( $context, JSON_UNESCAPED_UNICODE )
			) );
		}
		
		// wp_send_json_error() — отправляет JSON-ответ с ключом 'success' => false
		// Если передан контекст — объединяем с сообщением в массив для доступа из JS
		if ( ! empty( $context ) ) {
			wp_send_json_error( array_merge( array( 'message' => $message ), $context ) );
		} else {
			wp_send_json_error( $message );
		}
	}
	
	/**
	 * Быстрая отправка успешного ответа.
	 *
	 * @param array $data Данные для передачи
	 *
	 * @return void
	 */
	protected function success( array $data = array() ): void {
		wp_send_json_success( $data );
	}
}