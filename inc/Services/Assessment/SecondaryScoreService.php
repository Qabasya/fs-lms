<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

/**
 * Class SecondaryScoreService
 *
 * Переводит первичный балл ЕГЭ во вторичный по таблице перевода score_map (T7.14).
 * score_map хранится на работе (AssessmentDTO::scoreMap): primary => secondary.
 *
 * @package Inc\Services\Assessment
 */
class SecondaryScoreService {

	/**
	 * Переводит первичный балл во вторичный.
	 *
	 * @param float              $primaryScore Первичный балл (может быть дробным — округляем вниз).
	 * @param array<int, int>    $scoreMap     Таблица перевода: primary_score => secondary_score.
	 * @return int|null Вторичный балл или null, если таблица пуста или первичный балл не покрыт.
	 */
	public function translate( float $primaryScore, array $scoreMap ): ?int {
		if ( empty( $scoreMap ) ) {
			return null;
		}

		$primary = (int) floor( $primaryScore );

		if ( isset( $scoreMap[ $primary ] ) ) {
			return (int) $scoreMap[ $primary ];
		}

		// Если точного совпадения нет — ищем ближайший меньший ключ.
		$keys = array_filter( array_keys( $scoreMap ), static fn( int $k ) => $k <= $primary );
		if ( empty( $keys ) ) {
			return null;
		}

		return (int) $scoreMap[ max( $keys ) ];
	}
}
