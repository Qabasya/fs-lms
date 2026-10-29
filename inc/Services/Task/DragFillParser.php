<?php

declare( strict_types=1 );

namespace Inc\Services\Task;

/**
 * Class DragFillParser
 *
 * Разбор формулы задания «Перетаскивание пропусков»: слоты — `{N}`, нумерация с 1.
 * Номер слота в формуле = индекс в `task_drag_fill.slots` и в ответе ученика (с нуля).
 *
 * @package Inc\Services\Task
 */
class DragFillParser {

	private const SLOT = '/\{(\d+)\}/';

	/** Число слотов формулы — наибольший номер `{N}`. */
	public static function slotCount( string $formula ): int {
		preg_match_all( self::SLOT, $formula, $matches );

		return array() === $matches[1] ? 0 : max( array_map( 'intval', $matches[1] ) );
	}

	/**
	 * Сегменты для виджета: текст и слоты (`index` — с нуля).
	 *
	 * @return array<int, array{type: 'text'|'slot', content?: string, index?: int}>
	 */
	public static function segments( string $formula ): array {
		$parts    = preg_split( self::SLOT, $formula, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
		$segments = array();

		foreach ( $parts as $i => $part ) {
			if ( $i % 2 ) {
				$segments[] = array( 'type' => 'slot', 'index' => max( 0, (int) $part - 1 ) );
			} elseif ( '' !== $part ) {
				$segments[] = array( 'type' => 'text', 'content' => $part );
			}
		}

		return $segments;
	}

	/**
	 * Формула с подставленными плитками; пустой слот — «…».
	 *
	 * @param string[] $tiles Плитка по индексу слота.
	 */
	public static function render( string $formula, array $tiles ): string {
		return (string) preg_replace_callback(
			self::SLOT,
			static function ( array $m ) use ( $tiles ): string {
				$tile = trim( (string) ( $tiles[ max( 0, (int) $m[1] - 1 ) ] ?? '' ) );

				return '' !== $tile ? $tile : '…';
			},
			$formula
		);
	}
}
