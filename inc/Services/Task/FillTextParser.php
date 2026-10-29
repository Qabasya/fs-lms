<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

use Inc\Shared\Traits\AnswerNormalizer;

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

	use AnswerNormalizer;

	/** Режим ответа задания «Пропуски в тексте»: вписать в поле (по умолчанию) или перетащить плитки. */
	public const MODE_INPUT = 'input';
	public const MODE_TILES = 'tiles';

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
	 * Плитки банка для режима плиток: первый вариант каждого пропуска + лишние слова автора.
	 * Пустые отбрасываются; повторы остаются (два пропуска с одним словом — две плитки).
	 *
	 * @param ParsedFillText $parsed Результат parse().
	 * @param string[]       $extra  Лишние плитки (`extra_tiles`).
	 *
	 * @return string[]
	 */
	public static function tilesFor( ParsedFillText $parsed, array $extra ): array {
		$firsts = array_map( static fn( array $variants ): string => (string) ( $variants[0] ?? '' ), $parsed->gaps );

		return array_values( array_filter(
			array_map( 'trim', array_merge( $firsts, $extra ) ),
			static fn( string $tile ): bool => '' !== $tile
		) );
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
		$student = self::normalizeAnswer( $studentAnswer );

		foreach ( $answers as $answer ) {
			if ( $student === self::normalizeStoredAnswer( $answer ) ) {
				return true;
			}
		}

		return false;
	}
}
