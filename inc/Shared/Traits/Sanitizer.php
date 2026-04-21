<?php

namespace Inc\Shared\Traits;

/**
 * Trait Sanitizer
 *
 * Предоставляет методы для безопасного получения и санитизации данных
 * из суперглобальных массивов $_POST и $_GET.
 *
 * @package Inc\Shared\Traits
 */
trait Sanitizer {
	/**
	 * Получает и санирует строковое значение.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string Очищенная строка
	 */
	protected function sanitizeText( string $key, string $source = 'POST' ): string {
		$data  = $source === 'POST' ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';
		
		return sanitize_text_field( wp_unslash( is_string( $value ) ? $value : '' ) );
	}
	
	/**
	 * Получает и санирует ключ/ярлык (slug).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string Очищенный slug
	 */
	protected function sanitizeKey( string $key, string $source = 'POST' ): string {
		return sanitize_title( $this->sanitizeText( $key, $source ) );
	}
	
	/**
	 * Получает и санирует целое число.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return int Очищенное целое число
	 */
	protected function sanitizeInt( string $key, string $source = 'POST' ): int {
		$data = $source === 'POST' ? $_POST : $_GET;
		
		return absint( $data[ $key ] ?? 0 );
	}
	
	/**
	 * Санирует HTML-контент (для TinyMCE).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string Очищенный HTML
	 */
	protected function sanitizeHtml( string $key, string $source = 'POST' ): string {
		$data  = $source === 'POST' ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';
		
		return wp_kses_post( wp_unslash( $value ) );
	}
	
	/**
	 * Санирует контент из полей TinyMCE (поддерживает одиночные и составные шаблоны).
	 *
	 * Если передан массив полей, преобразует его в JSON.
	 * Если передана строка, возвращает её как есть.
	 *
	 * @param string $key    Ключ в суперглобальном массиве (обычно 'content')
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string Готовый контент (строка или JSON)
	 */
	protected function sanitizeEditorContent( string $key = 'content', string $source = 'POST' ): string {
		$data = $source === 'POST' ? $_POST : $_GET;
		$raw  = $data[ $key ] ?? [];
		
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return '';
		}
		
		$sanitized = [];
		foreach ( $raw as $field_id => $value ) {
			// sanitize_key для ID поля, wp_kses_post для сохранения верстки
			$sanitized[ sanitize_key( $field_id ) ] = wp_kses_post( wp_unslash( $value ) );
		}
		
		// Если поле одно — отдаем строку, если много — JSON
		return count( $sanitized ) === 1
			? (string) reset( $sanitized )
			: (string) wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE );
	}
	
	/**
	 * Требует наличие строкового ключа (slug/ID предмета).
	 * Завершает выполнение с ошибкой, если значение пустое.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $method Источник данных: 'POST' или 'GET'
	 * @param string $error  Сообщение об ошибке
	 *
	 * @return string Очищенный ключ
	 */
	protected function requireKey( string $key, string $method = 'POST', string $error = 'Недостаточно данных' ): string {
		$value = $this->sanitizeKey( $key, $method );
		if ( empty( $value ) ) {
			wp_send_json_error( $error );
		}
		
		return $value;
	}
	
	/**
	 * Требует наличие текстового поля (названия, контента).
	 * Завершает выполнение с ошибкой, если значение пустое.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $method Источник данных: 'POST' или 'GET'
	 * @param string $error  Сообщение об ошибке
	 *
	 * @return string Очищенный текст
	 */
	protected function requireText( string $key, string $method = 'POST', string $error = 'Поле обязательно для заполнения' ): string {
		$value = $this->sanitizeText( $key, $method );
		if ( empty( $value ) ) {
			wp_send_json_error( $error );
		}
		
		return $value;
	}
	
	/**
	 * Требует наличие целого числа (ID термина, ID поста).
	 * Завершает выполнение с ошибкой, если значение равно 0.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $method Источник данных: 'POST' или 'GET'
	 * @param string $error  Сообщение об ошибке
	 *
	 * @return int Очищенное целое число
	 */
	protected function requireInt( string $key, string $method = 'POST', string $error = 'Неверный идентификатор' ): int {
		$value = $this->sanitizeInt( $key, $method );
		if ( 0 === $value ) {
			wp_send_json_error( $error );
		}
		
		return $value;
	}
}