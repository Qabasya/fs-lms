<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

use Inc\Shared\SafeHtml;

/**
 * Class LegacyTaskRowDTO
 *
 * Одна запись файла переноса заданий со старой версии сайта
 * (`legacy_tasks_import.json`, загружается со страницы переноса).
 *
 * @package Inc\DTO\Task
 *
 * ### Санитизация
 *
 * Строки приходят из `json_decode()` уже без слэшей, поэтому value-хелперы
 * трейта `Sanitizer` (они сами зовут `wp_unslash()`) здесь не подходят: съели бы
 * обратные слэши условия и кода. Правила повторяют поля метабокса задания:
 * условие — `ConditionField`, ответ — `TextareaField`, код — `CodeField`
 * (как есть), файл — `LinkField`.
 *
 * Старый сайт хранил формулы с голым `<` (`$(x < A) \lor (y > 400)$`, `$n<10$`,
 * `3<n<10000`), а kses принимает `< A) \lor (y >` за тег и вырезает кусок
 * условия. Поэтому до kses экранируется каждый `<`, за которым не начинается
 * разрешённый в записях тег.
 */
readonly class LegacyTaskRowDTO {

	/**
	 * @param int    $legacyNumber  Порядковый номер задания на старом сайте (ключ дедупликации)
	 * @param int    $egeNumber     Номер задания ЕГЭ
	 * @param string $variantLabel  Подпись варианта — заголовок черновика
	 * @param string $author        Название термина автора
	 * @param string $year          Название термина года
	 * @param string $level         Название термина сложности
	 * @param string $conditionHtml Условие (HTML)
	 * @param string $answer        Правильный ответ
	 * @param string $codePython    Код решения
	 * @param string $fileUrl       Ссылка на файл задания
	 */
	public function __construct(
		public int $legacyNumber,
		public int $egeNumber,
		public string $variantLabel,
		public string $author,
		public string $year,
		public string $level,
		public string $conditionHtml,
		public string $answer,
		public string $codePython,
		public string $fileUrl,
	) {}

	/**
	 * Собирает запись из элемента JSON-массива.
	 *
	 * @param mixed $row Элемент массива записей; не-объект даёт пустую запись (будет пропущена без номера ЕГЭ)
	 *
	 * @return self
	 */
	public static function fromArray( mixed $row ): self {
		$row = is_array( $row ) ? $row : array();

		$string = static fn( string $key ): string => is_scalar( $row[ $key ] ?? null ) ? (string) $row[ $key ] : '';

		return new self(
			legacyNumber:  absint( $string( 'legacy_number' ) ),
			egeNumber:     absint( $string( 'ege_number' ) ),
			variantLabel:  sanitize_text_field( $string( 'variant_label' ) ),
			author:        sanitize_text_field( $string( 'author' ) ),
			year:          sanitize_text_field( $string( 'year' ) ),
			level:         sanitize_text_field( $string( 'level' ) ),
			conditionHtml: trim( SafeHtml::post( self::escapeBareLessThan( $string( 'condition_html' ) ) ) ),
			answer:        trim( sanitize_textarea_field( $string( 'answer' ) ) ),
			codePython:    trim( $string( 'code_python' ) ),
			fileUrl:       esc_url_raw( trim( $string( 'file_url' ) ) ),
		);
	}

	/**
	 * Экранирует `<`, который не открывает и не закрывает разрешённый тег.
	 *
	 * @param string $html Условие со старого сайта
	 *
	 * @return string
	 */
	private static function escapeBareLessThan( string $html ): string {
		$tags = implode(
			'|',
			array_map( static fn( string $tag ): string => preg_quote( $tag, '/' ), array_keys( wp_kses_allowed_html( 'post' ) ) )
		);

		return (string) preg_replace( '/<(?!\/?(?:' . $tags . ')[\s>\/])/i', '&lt;', $html );
	}
}
