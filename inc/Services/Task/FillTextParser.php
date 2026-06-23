<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class FillTextParser
 *
 * Парсит текст с пропусками формата [[ответ]] / [[вар1|вар2]].
 * Используется чекером (T6.8) и фронтенд-рендерером.
 *
 * Синтаксис:
 *   [[ответ]]          — один допустимый вариант
 *   [[красный|алый]]   — любой из вариантов принят (регистронезависимо)
 *
 * @package Inc\Services\Task
 */
class FillTextParser {

	/**
	 * Парсит текст и возвращает сегменты + список пропусков.
	 *
	 * @param string $text Сырой текст с разметкой [[...]].
	 *
	 * @return ParsedFillText
	 */
	public static function parse( string $text ): ParsedFillText {
		$pattern  = '/\[\[([^\]]+)\]\]/u';
		$segments = array();
		$gaps     = array();
		$offset   = 0;
		$gapIndex = 0;

		preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE );

		foreach ( $matches[0] as $k => $match ) {
			[ $full, $pos ] = $match;
			$inner           = $matches[1][ $k ][0];

			if ( $pos > $offset ) {
				$segments[] = array(
					'type'    => 'text',
					'content' => substr( $text, $offset, $pos - $offset ),
				);
			}

			$answers = array_map( 'trim', explode( '|', $inner ) );

			$segments[] = array(
				'type'  => 'gap',
				'index' => $gapIndex,
			);

			$gaps[] = $answers;
			++$gapIndex;
			$offset = $pos + strlen( $full );
		}

		if ( $offset < strlen( $text ) ) {
			$segments[] = array(
				'type'    => 'text',
				'content' => substr( $text, $offset ),
			);
		}

		return new ParsedFillText( $segments, $gaps );
	}

	/**
	 * Проверяет ответ ученика на пропуск по его индексу.
	 *
	 * @param ParsedFillText $parsed        Результат parse().
	 * @param int            $gapIndex      Индекс пропуска (0-based).
	 * @param string         $studentAnswer Ответ ученика.
	 *
	 * @return bool
	 */
	public static function checkGap( ParsedFillText $parsed, int $gapIndex, string $studentAnswer ): bool {
		$answers = $parsed->gaps[ $gapIndex ] ?? array();
		$student = mb_strtolower( trim( $studentAnswer ) );

		foreach ( $answers as $answer ) {
			if ( $student === mb_strtolower( trim( $answer ) ) ) {
				return true;
			}
		}

		return false;
	}
}
