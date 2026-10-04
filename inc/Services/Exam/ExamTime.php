<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Contracts\ClockInterface;

/**
 * Единственное место перевода времени между UTC (таблицы exam_*) и местным временем сайта
 * (старые таблицы: assessment_attempts, group_lessons). Сравнивать время новой и старой таблицы
 * без этого класса нельзя.
 */
class ExamTime {

	private const FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		private readonly ClockInterface $clock,
	) {}

	public function nowUtc(): string {
		return $this->clock->now( 'mysql', true );
	}

	public function nowLocal(): string {
		return $this->clock->now();
	}

	/** Местное время сайта → UTC. */
	public function toUtc( string $local ): string {
		return ( new \DateTimeImmutable( $local, wp_timezone() ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( self::FORMAT );
	}

	/** UTC → местное время сайта. */
	public function toLocal( string $utc ): string {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )
			->setTimezone( wp_timezone() )
			->format( self::FORMAT );
	}

	/** Прибавляет минуты к «настенному» времени (как делает AttemptService для дедлайна). */
	public function addMinutes( string $datetime, int $minutes ): string {
		return ( new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) ) )
			->modify( sprintf( '%+d minutes', $minutes ) )
			->format( self::FORMAT );
	}

	/**
	 * Секунды от `$from` до `$to` для «настенного» времени одной зоны (оба — местные или оба — UTC); не меньше нуля.
	 */
	public function secondsUntil( string $from, string $to ): int {
		$utc = new \DateTimeZone( 'UTC' );

		return max( 0, ( new \DateTimeImmutable( $to, $utc ) )->getTimestamp() - ( new \DateTimeImmutable( $from, $utc ) )->getTimestamp() );
	}

	/** 23:59:59 местного дня `Y-m-d`, выраженные в UTC. */
	public function endOfLocalDayUtc( string $date ): string {
		return $this->toUtc( $date . ' 23:59:59' );
	}
}
