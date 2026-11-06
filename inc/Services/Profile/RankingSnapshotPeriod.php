<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use DateTimeImmutable;
use DateTimeZone;

/** Границы периодов сравнения мест в часовом поясе сайта. */
class RankingSnapshotPeriod {

	public const INTERVAL = 'day';
	/** Запас для тика каждые 15 минут и времени вычисления снимка. */
	public const CAPTURE_WINDOW_SECONDS = 1800;

	public function inCaptureWindow( DateTimeImmutable $instant, DateTimeZone $timezone ): bool {
		$elapsed = $instant->getTimestamp() - $this->start( $instant, $timezone )->getTimestamp();
		return $elapsed >= 0 && $elapsed <= self::CAPTURE_WINDOW_SECONDS;
	}

	/** Начало календарного периода, содержащего указанный момент. */
	public function start( DateTimeImmutable $instant, DateTimeZone $timezone ): DateTimeImmutable {
		$local = $instant->setTimezone( $timezone );
		return match ( self::INTERVAL ) {
			'day'   => $local->setTime( 0, 0 ),
			'week'  => $local->modify( 'monday this week' )->setTime( 0, 0 ),
			'month' => $local->modify( 'first day of this month' )->setTime( 0, 0 ),
			default => throw new \LogicException( 'Неизвестный период снимка рейтинга.' ),
		};
	}

	/** Предыдущая календарная граница, в том числе при переходе летнего времени. */
	public function previousStart( DateTimeImmutable $instant, DateTimeZone $timezone ): DateTimeImmutable {
		$start = $this->start( $instant, $timezone );
		return match ( self::INTERVAL ) {
			'day'   => $start->modify( '-1 day' ),
			'week'  => $start->modify( '-1 week' ),
			'month' => $start->modify( '-1 month' ),
			default => throw new \LogicException( 'Неизвестный период снимка рейтинга.' ),
		};
	}
}
