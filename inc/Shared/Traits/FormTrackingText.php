<?php

declare( strict_types=1 );

namespace Inc\Shared\Traits;

/**
 * Trait FormTrackingText
 *
 * Общие формулировки журналов публичных форм (заявка, форма родителя, запись на экзамен):
 * «через 2 мин 5 с», «заполнено 3 из 8 полей». Один текст на все журналы.
 *
 * @package Inc\Shared\Traits
 */
trait FormTrackingText {

	/**
	 * Длительность словами: «45 с», «2 мин 5 с», «1 ч 3 мин».
	 */
	private function duration( int $seconds ): string {
		$seconds = max( 0, $seconds );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return $seconds . ' с';
		}

		$minutes = intdiv( $seconds, MINUTE_IN_SECONDS );

		return $minutes < 60
			? sprintf( '%d мин %d с', $minutes, $seconds % MINUTE_IN_SECONDS )
			: sprintf( '%d ч %d мин', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * Сколько полей формы заполнено; число полей неизвестно — только заполненные.
	 */
	private function filledText( int $filled, int $total ): string {
		return $total > 0
			? sprintf( 'заполнено %d из %d полей', $filled, $total )
			: sprintf( 'заполнено полей: %d', $filled );
	}
}
