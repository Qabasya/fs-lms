<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum WeekDay: string {

	case Monday    = 'mon';
	case Tuesday   = 'tue';
	case Wednesday = 'wed';
	case Thursday  = 'thu';
	case Friday    = 'fri';
	case Saturday  = 'sat';
	case Sunday    = 'sun';

	public function label(): string {
		return match ( $this ) {
			self::Monday    => 'Пн',
			self::Tuesday   => 'Вт',
			self::Wednesday => 'Ср',
			self::Thursday  => 'Чт',
			self::Friday    => 'Пт',
			self::Saturday  => 'Сб',
			self::Sunday    => 'Вс',
		};
	}

	public function fullLabel(): string {
		return match ( $this ) {
			self::Monday    => 'Понедельник',
			self::Tuesday   => 'Вторник',
			self::Wednesday => 'Среда',
			self::Thursday  => 'Четверг',
			self::Friday    => 'Пятница',
			self::Saturday  => 'Суббота',
			self::Sunday    => 'Воскресенье',
		};
	}

	/**
	 * Форматирует массив ключей дней в читаемую строку.
	 * Например: ['mon', 'wed', 'fri'] → 'Пн, Ср, Пт'
	 *
	 * @param string[] $days
	 */
	public static function formatDays( array $days ): string {
		$labels = array();

		foreach ( $days as $key ) {
			$day = self::tryFrom( $key );
			if ( $day !== null ) {
				$labels[] = $day->label();
			}
		}

		return implode( ', ', $labels );
	}
}
