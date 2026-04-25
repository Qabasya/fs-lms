<?php

namespace Inc\Shared\Traits;

/**
 * Trait Sanitizer
 *
 * Предоставляет методы для безопасного получения и санитизации данных
 * из суперглобальных массивов $_POST и $_GET.
 *
 * @package Inc\Shared\Traits
 *
 * ### Основные обязанности:
 *
 * 1. **Получение и санитизация** — безопасное извлечение данных с очисткой.
 * 2. **Валидация** — проверка наличия обязательных полей.
 * 3. **Обработка HTML** — санитизация контента для TinyMCE.
 *
 * ### Архитектурная роль:
 *
 * Предоставляет унифицированные методы для всех классов-обработчиков (коллбеков),
 * гарантируя безопасность получаемых данных (защита от XSS).
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
		// Выбор источника данных
		$data  = $source === 'POST' ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';
		
		// wp_unslash() — удаляет экранирование слешей
		// sanitize_text_field() — удаляет теги и спецсимволы
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
		// sanitize_title() — преобразует строку в slug (транслитерация, нижний регистр, дефисы)
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
		
		// absint() — преобразует значение в абсолютное целое число (без знака)
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
		
		// wp_kses_post() — разрешает только безопасные HTML-теги (для контента постов)
		return wp_kses_post( wp_unslash( $value ) );
	}
	
	/**
	 * Санирует контент из полей TinyMCE (поддерживает одиночные и составные шаблоны).
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
			// sanitize_key — для ID поля (только буквы/цифры/дефисы)
			// wp_kses_post — для сохранения безопасной верстки
			$sanitized[ sanitize_key( $field_id ) ] = wp_kses_post( wp_unslash( $value ) );
		}
		
		// Если одно поле — возвращаем строку, если несколько — JSON
		// reset() — возвращает первый элемент массива
		return count( $sanitized ) === 1
			? (string) reset( $sanitized )
			// wp_json_encode(, JSON_UNESCAPED_UNICODE) — JSON без экранирования Unicode
			: (string) wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE );
	}
	
	/**
	 * Требует наличие строкового ключа (slug/ID предмета).
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
	 * Получает и санирует булево значение.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return bool
	 */
	protected function sanitizeBool( string $key, string $source = 'POST' ): bool {
		$data  = $source === 'POST' ? $_POST : $_GET;
		$value = $data[ $key ] ?? null;
		
		// in_array() с проверкой на различные представления "истины"
		return in_array( $value, array( '1', 'on', 'true', true, 1 ), true );
	}
	
	/**
	 * Требует наличие целого числа (ID термина, ID поста).
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