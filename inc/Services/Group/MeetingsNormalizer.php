<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Enums\Course\WeekDay;

/**
 * Class MeetingsNormalizer
 *
 * Единый нормализатор расписания группы (`groups.meetings`).
 *
 * Историческая запись формы — `{day:<слаг>, start, end}` (читается дисплей-хелперами
 * WeekDay::format*). Календарь (SessionCalendarService) ожидает канон
 * `{weekday:1–7, time:"HH:MM", duration_min}`.
 *
 * Чтобы не ломать дисплей, храним и отдаём **надмножество** — запись несёт оба набора
 * ключей. Нормализатор — единственный источник деривации канонических полей из формы.
 *
 * @package Inc\Services\Group
 */
class MeetingsNormalizer {

	/**
	 * Нормализует список записей расписания, отбрасывая невалидные дни.
	 *
	 * @param array<mixed> $entries
	 * @return array<int, array{day:string,start:string,end:string,weekday:int,time:string,duration_min:int}>
	 */
	public static function normalizeList( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$normalized = self::normalizeEntry( $entry );
			if ( null !== $normalized ) {
				$out[] = $normalized;
			}
		}

		return $out;
	}

	/**
	 * Превращает запись `{day,start,end}` в надмножество с каноническими полями.
	 * Возвращает null, если день недели невалиден.
	 *
	 * @param array<string,mixed> $entry
	 * @return array{day:string,start:string,end:string,weekday:int,time:string,duration_min:int}|null
	 */
	public static function normalizeEntry( array $entry ): ?array {
		$day = WeekDay::tryFrom( (string) ( $entry['day'] ?? '' ) );
		if ( null === $day ) {
			return null;
		}

		$start = (string) ( $entry['start'] ?? '' );
		$end   = (string) ( $entry['end'] ?? '' );

		return array(
			'day'          => $day->value,
			'start'        => $start,
			'end'          => $end,
			'weekday'      => $day->isoNumber(),
			'time'         => $start,
			'duration_min' => self::durationMinutes( $start, $end ),
		);
	}

	/**
	 * Длительность занятия в минутах из "HH:MM"–"HH:MM".
	 * Фолбэк 60 мин, если время не распарсилось или end ≤ start.
	 */
	private static function durationMinutes( string $start, string $end ): int {
		$s = self::toMinutes( $start );
		$e = self::toMinutes( $end );
		if ( null === $s || null === $e || $e <= $s ) {
			return 60;
		}

		return $e - $s;
	}

	/** "HH:MM" → минуты от полуночи, либо null при невалидном вводе. */
	private static function toMinutes( string $hhmm ): ?int {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $hhmm, $m ) ) {
			return null;
		}
		$h   = (int) $m[1];
		$min = (int) $m[2];
		if ( $h > 23 || $min > 59 ) {
			return null;
		}

		return $h * 60 + $min;
	}
}