<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

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

	/**
	 * Форматирует массив расписания группы в строку вида "Понедельник, Вторник 13:30 - 15:00".
	 *
	 * @param array<string, mixed> $schedule Массив с ключами 'days', 'start', 'end'
	 */
	/**
	 * Нормализует schedule к формату [{day, start, end}, ...].
	 * Поддерживает старый формат {days: [...], start, end}.
	 *
	 * @param array<mixed> $schedule
	 * @return array<int, array{day: string, start: string, end: string}>
	 */
	private static function normalizeSchedule( array $schedule ): array {
		if ( isset( $schedule['days'] ) ) {
			$start = (string) ( $schedule['start'] ?? '' );
			$end   = (string) ( $schedule['end']   ?? '' );
			$result = array();
			foreach ( (array) $schedule['days'] as $d ) {
				$result[] = array( 'day' => (string) $d, 'start' => $start, 'end' => $end );
			}
			return $result;
		}

		return array_values( array_filter( $schedule, fn( $e ) => is_array( $e ) && isset( $e['day'] ) ) );
	}

	/**
	 * Форматирует расписание в строку вида "Понедельник 16:00 - 17:30\nЧетверг 16:00 - 17:30".
	 *
	 * @param array<mixed> $schedule
	 */
	public static function formatScheduleFull( array $schedule ): string {
		$lines = array();

		foreach ( self::normalizeSchedule( $schedule ) as $entry ) {
			$day = self::tryFrom( $entry['day'] );
			if ( $day === null ) {
				continue;
			}
			$start = $entry['start'];
			$end   = $entry['end'];
			$time  = ( $start !== '' && $end !== '' ) ? ' ' . $start . ' - ' . $end : ( $start !== '' ? ' ' . $start : '' );
			$lines[] = $day->fullLabel() . $time;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Форматирует расписание в строку вида "Пн 16:00-17:30, Чт 16:00-17:30".
	 *
	 * @param array<mixed> $schedule
	 */
	public static function formatSchedule( array $schedule ): string {
		$parts = array();

		foreach ( self::normalizeSchedule( $schedule ) as $entry ) {
			$day = self::tryFrom( $entry['day'] );
			if ( $day === null ) {
				continue;
			}
			$start = $entry['start'];
			$end   = $entry['end'];
			$time  = ( $start !== '' && $end !== '' ) ? ' ' . $start . '-' . $end : ( $start !== '' ? ' ' . $start : '' );
			$parts[] = $day->label() . $time;
		}

		return implode( ', ', $parts );
	}
}
