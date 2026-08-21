<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

use Inc\Shared\SafeHtml;

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
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';
		
		// wp_unslash() — удаляет экранирование слешей
		// sanitize_text_field() — удаляет теги и спецсимволы
		return sanitize_text_field( wp_unslash( is_string( $value ) ? $value : '' ) );
	}
	
	/**
	 * Получает и санирует многострочный текст (textarea).
	 *
	 * Отличие от {@see sanitizeText()}: переводы строк сохраняются — нужно там,
	 * где сама разбивка на строки несёт смысл (вставка таблицы из Excel и т.п.).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string Очищенный многострочный текст
	 */
	protected function sanitizeMultilineText( string $key, string $source = 'POST' ): string {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';

		return sanitize_textarea_field( wp_unslash( is_string( $value ) ? $value : '' ) );
	}

	/**
	 * Получает и очищает ответ ученика — текст «как набрано».
	 *
	 * Отличие от {@see sanitizeMultilineText()}: теги НЕ вырезаются. И
	 * `sanitize_textarea_field()`, и `wp_kses_post()` считают `<` началом
	 * разметки, а в ответе по информатике это оператор сравнения: `a<b`
	 * превращался в `a&lt;b` (сличение с эталоном давало 0 баллов), а
	 * `2<3 и 5>4` — в `24` (ответ уничтожался целиком). Кавычки при этом
	 * экранировались, и JSON составного ответа (19–21) переставал разбираться.
	 *
	 * Безопасность держится на выводе: ответ рендерится только через
	 * `esc_html()` в шаблонах и `escapeHtml()` в JS, в запросы уходит через
	 * `$wpdb->prepare()`. Здесь снимаются лишь слэши и управляющие символы,
	 * переводы строк остаются (табличный ответ КЕГЭ).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string Ответ ученика без управляющих символов
	 */
	protected function sanitizeAnswerText( string $key, string $source = 'POST' ): string {
		$data = 'POST' === $source ? $_POST : $_GET;

		return $this->sanitizeAnswerTextValue( wp_unslash( $data[ $key ] ?? '' ) );
	}

	/**
	 * Значение ответа из уже полученного массива.
	 *
	 * Слэши здесь НЕ снимаются: вызывающий код читает ответы через
	 * {@see unslashArray()}, и повторный `wp_unslash()` съел бы обратные слэши
	 * самого ответа (путь, регулярка, escape-последовательность в коде).
	 *
	 * @see sanitizeAnswerText() Причины, по которым теги здесь не вырезаются
	 *
	 * @param mixed $value Значение, со снятыми слэшами
	 *
	 * @return string Ответ ученика без управляющих символов
	 */
	protected function sanitizeAnswerTextValue( mixed $value ): string {
		// Управляющие символы (кроме перевода строки и табуляции) и невалидный
		// UTF-8: в ответе им места нет, а в БД они ломают сравнение с эталоном.
		$text = wp_check_invalid_utf8( is_string( $value ) ? $value : '' );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		return trim( (string) preg_replace( '/[^\P{C}\n\t]+/u', '', $text ) );
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
		$data = 'POST' === $source ? $_POST : $_GET;
		
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
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';

		// SafeHtml::post() — wp_kses_post() (безопасные HTML-теги для контента постов)
		// с восстановлением data-URI картинок, см. её докблок
		return SafeHtml::post( wp_unslash( $value ) );
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
		$data = 'POST' === $source ? $_POST : $_GET;
		$raw  = $data[ $key ] ?? [];
		
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return '';
		}
		
		$sanitized = [];
		foreach ( $raw as $field_id => $value ) {
			// sanitize_key — для ID поля (только буквы/цифры/дефисы)
			// SafeHtml::post() — для сохранения безопасной верстки
			$sanitized[ sanitize_key( $field_id ) ] = SafeHtml::post( wp_unslash( $value ) );
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
		$data  = 'POST' === $source ? $_POST : $_GET;
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

	/**
	 * Получает и санирует массив ключей/слагов.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string[]
	 */
	protected function sanitizeKeyArray( string $key, string $source = 'POST' ): array {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? array();

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_key', wp_unslash( $value ) ) ) );
	}

	/**
	 * Получает и санирует массив целых чисел (списки ID из формы/запроса).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return int[] Значения в исходном порядке; нули сохраняются (фильтровать — вызывающему коду)
	 */
	protected function sanitizeIntList( string $key, string $source = 'POST' ): array {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? array();

		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_map( 'intval', wp_unslash( $value ) ) );
	}

	/**
	 * Синоним {@see sanitizeKeyArray()} — единообразие с sanitizeIntList().
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string[]
	 */
	protected function sanitizeKeyList( string $key, string $source = 'POST' ): array {
		return $this->sanitizeKeyArray( $key, $source );
	}

	/**
	 * Снимает экранирование WP с массива произвольной структуры (шаги урока,
	 * модули курса, мета задания, карта фильтров).
	 *
	 * Санитайзинг значений — на вызывающем коде: структура доменная, трейт о ней
	 * не знает. Не-массив (и отсутствующий ключ) даёт пустой массив.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return array
	 */
	protected function unslashArray( string $key, string $source = 'POST' ): array {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? array();

		return is_array( $value ) ? (array) wp_unslash( $value ) : array();
	}

	protected function sanitizeEmail( string $key, string $source = 'POST' ): string {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';
		return sanitize_email( wp_unslash( is_string( $value ) ? $value : '' ) );
	}

	/**
	 * Проверяет наличие ключа в запросе (без чтения значения).
	 *
	 * Единственная точка isset()-проверок по суперглобалам: «поле пришло из формы»
	 * (чекбоксы, маркеры скрытых форм, tax_input) — значение потом читается
	 * санитайзящими методами.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST', 'GET' или 'REQUEST'
	 *
	 * @return bool
	 */
	protected function hasParam( string $key, string $source = 'POST' ): bool {
		$data = match ( $source ) {
			'GET'     => $_GET,
			'REQUEST' => $_REQUEST,
			default   => $_POST,
		};

		return isset( $data[ $key ] );
	}

	/**
	 * Получает и санирует дробное число (баллы, оценки).
	 *
	 * @param string $key     Ключ в суперглобальном массиве
	 * @param string $source  Источник данных: 'POST' или 'GET'
	 * @param float  $default Значение при отсутствии ключа
	 *
	 * @return float
	 */
	protected function sanitizeFloat( string $key, string $source = 'POST', float $default = 0.0 ): float {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? $default;

		return is_scalar( $value ) ? (float) $value : $default;
	}

	/**
	 * Целое число либо null, если ключ отсутствует или пуст (необязательные ID).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return int|null
	 */
	protected function sanitizeIntOrNull( string $key, string $source = 'POST' ): ?int {
		$data = 'POST' === $source ? $_POST : $_GET;
		if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
			return null;
		}

		return $this->sanitizeInt( $key, $source );
	}

	/**
	 * Текст либо null, если ключ отсутствует или пуст (необязательные поля).
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string|null
	 */
	protected function sanitizeTextOrNull( string $key, string $source = 'POST' ): ?string {
		$data = 'POST' === $source ? $_POST : $_GET;
		if ( ! isset( $data[ $key ] ) || '' === $data[ $key ] ) {
			return null;
		}

		return $this->sanitizeText( $key, $source );
	}

	/**
	 * Сырая строка из запроса: только wp_unslash, без sanitize_text_field.
	 *
	 * Для JSON-пейлоадов и подобных структурных строк, которые прогонять через
	 * text-field-санитайз нельзя (порежет содержимое) — валидация структурная
	 * (json_decode и доменные проверки) на вызывающей стороне.
	 *
	 * @param string $key    Ключ в суперглобальном массиве
	 * @param string $source Источник данных: 'POST' или 'GET'
	 *
	 * @return string
	 */
	protected function unslashRawString( string $key, string $source = 'POST' ): string {
		$data  = 'POST' === $source ? $_POST : $_GET;
		$value = $data[ $key ] ?? '';

		return is_string( $value ) ? wp_unslash( $value ) : '';
	}

	/**
	 * Дескриптор загруженного файла из $_FILES; null — файл не передан.
	 *
	 * Значения (tmp_name, size, error) не санитизируются: их валидирует
	 * обработчик загрузки (MediaManager / доменный код).
	 *
	 * @param string $key Ключ в $_FILES
	 *
	 * @return array<string, mixed>|null
	 */
	protected function uploadedFile( string $key ): ?array {
		$file = $_FILES[ $key ] ?? null;

		return is_array( $file ) ? $file : null;
	}

	protected function sanitizeGetKey( string $key ): string {
		return $this->sanitizeKey( $key, 'GET' );
	}

	protected function sanitizeGetText( string $key ): string {
		return $this->sanitizeText( $key, 'GET' );
	}

	protected function sanitizeGetInt( string $key ): int {
		return $this->sanitizeInt( $key, 'GET' );
	}

	// ── Value-based helpers (for FieldInterface::sanitize implementations) ──

	protected function sanitizeTextValue( mixed $value ): string {
		return sanitize_text_field( wp_unslash( is_string( $value ) ? $value : (string) $value ) );
	}

	/**
	 * Многострочный текст: теги вырезаются, переводы строк сохраняются.
	 *
	 * @see sanitizeTextValue() — однострочный вариант (переносы схлопываются).
	 */
	protected function sanitizeMultilineTextValue( mixed $value ): string {
		return sanitize_textarea_field( wp_unslash( is_string( $value ) ? $value : (string) $value ) );
	}

	protected function sanitizeHtmlValue( mixed $value ): string {
		return SafeHtml::post( wp_unslash( is_string( $value ) ? $value : (string) $value ) );
	}

	protected function sanitizeKeyValue( mixed $value ): string {
		return sanitize_title( $this->sanitizeTextValue( $value ) );
	}

	protected function sanitizeIntValue( mixed $value ): int {
		return absint( is_scalar( $value ) ? $value : 0 );
	}
}
