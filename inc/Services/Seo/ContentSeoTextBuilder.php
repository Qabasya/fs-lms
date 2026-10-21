<?php

declare( strict_types=1 );

namespace Inc\Services\Seo;

/**
 * Class ContentSeoTextBuilder
 *
 * Тексты `title` и `description` для публичных страниц банков (задания, статьи):
 * чистая логика без обращений к WordPress, поэтому покрыта юнит-тестами.
 *
 * @package Inc\Services\Seo
 *
 * ### Зачем
 *
 * Заголовок записи — рабочее имя для преподавателя («№ 6203. Авторская задача»), в
 * выдаче он ничего не говорит про предмет и номер задания, а у десятков задач совпадает.
 * Здесь из тех же данных собираются запросные формулировки:
 * «Задание 6 ЕГЭ информатика № 6203 — решение».
 */
class ContentSeoTextBuilder {

	/** Предел длины `description`, символов (Яндекс и Google обрезают около 160). */
	public const DESCRIPTION_LIMIT = 160;

	/** Слова-экзамены в конце названия предмета: «Информатика ЕГЭ» → «ЕГЭ информатика». */
	private const EXAM_WORDS = array( 'ЕГЭ', 'ОГЭ' );

	/**
	 * Название предмета в запросном порядке слов.
	 *
	 * «Информатика ЕГЭ» → «ЕГЭ информатика»: так ищут. Название без экзамена в конце
	 * («Python») остаётся как есть.
	 *
	 * @param string $subject_name Название предмета из настроек.
	 *
	 * @return string
	 */
	public function examLabel( string $subject_name ): string {
		$words = preg_split( '/\s+/u', trim( $subject_name ), -1, PREG_SPLIT_NO_EMPTY );
		$words = false === $words ? array() : $words;
		$last  = end( $words );

		if ( count( $words ) < 2 || ! in_array( $last, self::EXAM_WORDS, true ) ) {
			return trim( $subject_name );
		}

		array_pop( $words );

		return $last . ' ' . mb_strtolower( implode( ' ', $words ) );
	}

	/**
	 * Номер задания экзамена из названия термина: «6 задание» → «6».
	 *
	 * @param string $term_name Название термина таксономии номеров.
	 *
	 * @return string Пустая строка — числа в названии нет.
	 */
	public function taskNumber( string $term_name ): string {
		return 1 === preg_match( '/\d+/u', $term_name, $m ) ? $m[0] : '';
	}

	/**
	 * Номер задачи в банке: из заголовка «№ 6203. …», иначе из слага.
	 *
	 * @param string $post_title Заголовок записи.
	 * @param string $post_slug  Слаг записи.
	 *
	 * @return string Пустая строка — номера нет.
	 */
	public function problemNumber( string $post_title, string $post_slug ): string {
		if ( 1 === preg_match( '/^\s*№[\s\x{00A0}]*(\d+)/u', $post_title, $m ) ) {
			return $m[1];
		}

		return ctype_digit( $post_slug ) ? $post_slug : '';
	}

	/**
	 * Title задачи без названия сайта: запросный префикс и название записи как есть.
	 *
	 * Название задачи («№ 12017. Демоверсия») не переписывается: пометки вроде
	 * «Демоверсия» или «Пересдача» остаются и отличают заголовки однотипных задач.
	 *
	 * @param string $exam_label  Название предмета ({@see examLabel()}).
	 * @param string $task_number Номер задания экзамена; '' — неизвестен.
	 * @param string $post_title  Заголовок записи.
	 *
	 * @return string
	 */
	public function taskTitle( string $exam_label, string $task_number, string $post_title ): string {
		$name = $this->collapse( $post_title );
		$head = '' === $task_number
			? $exam_label
			: sprintf( 'Задание %s %s', $task_number, $exam_label );

		if ( '' === trim( $head ) ) {
			return $name;
		}

		return '' === $name ? $head : sprintf( '%s: %s', $head, $name );
	}

	/**
	 * Description задачи: начало условия, добитое до предела пояснением.
	 *
	 * @param string $exam_label     Название предмета ({@see examLabel()}).
	 * @param string $task_number    Номер задания экзамена; '' — неизвестен.
	 * @param string $problem_number Номер задачи в банке; '' — неизвестен.
	 * @param string $condition      Условие задачи простым текстом; '' — нет.
	 *
	 * @return string
	 */
	public function taskDescription( string $exam_label, string $task_number, string $problem_number, string $condition ): string {
		$head = '' === $task_number ? $exam_label : sprintf( 'Задание %s %s', $task_number, $exam_label );
		$head = '' === $problem_number ? $head : sprintf( '%s № %s', $head, $problem_number );
		$tail = ' Ответ и разбор решения.';

		$condition = $this->collapse( $condition );

		if ( '' === $condition ) {
			return sprintf( '%s: условие, ответ и разбор решения.', $head );
		}

		$room = self::DESCRIPTION_LIMIT - mb_strlen( $head ) - 2 - mb_strlen( $tail );

		return sprintf( '%s: %s%s', $head, $this->cut( $condition, $room ), $tail );
	}

	/**
	 * Description статьи: краткое описание автора, иначе начало текста.
	 *
	 * @param string $short_description Поле «Краткое описание» статьи.
	 * @param string $content           Текст статьи простым текстом.
	 *
	 * @return string Пустая строка — взять нечего, решает вызывающий.
	 */
	public function articleDescription( string $short_description, string $content ): string {
		$source = '' !== trim( $short_description ) ? $short_description : $content;

		return $this->cut( $this->collapse( $source ), self::DESCRIPTION_LIMIT );
	}

	/**
	 * Схлопывает пробелы и переносы в один пробел.
	 *
	 * @param string $text Текст.
	 *
	 * @return string
	 */
	private function collapse( string $text ): string {
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) ?? '' );
	}

	/**
	 * Обрезает по границе слова, добавляя «…»; короткий текст не трогает.
	 *
	 * @param string $text  Текст со схлопнутыми пробелами.
	 * @param int    $limit Максимум символов вместе с многоточием.
	 *
	 * @return string
	 */
	private function cut( string $text, int $limit ): string {
		if ( $limit <= 1 || mb_strlen( $text ) <= $limit ) {
			return $limit <= 1 ? '' : $text;
		}

		$head  = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $head, ' ' );

		if ( false !== $space && $space > $limit / 2 ) {
			$head = mb_substr( $head, 0, $space );
		}

		return rtrim( $head, ' ,.;:—-' ) . '…';
	}
}
