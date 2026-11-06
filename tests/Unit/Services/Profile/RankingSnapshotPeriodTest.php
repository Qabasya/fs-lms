<?php

declare( strict_types=1 );

namespace Unit\Services\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Inc\Services\Profile\RankingSnapshotPeriod;
use PHPUnit\Framework\TestCase;

class RankingSnapshotPeriodTest extends TestCase {
	public function test_site_midnight_uses_local_calendar(): void {
		$period = new RankingSnapshotPeriod();
		$zone   = new DateTimeZone( 'Europe/Kaliningrad' );
		$now    = new DateTimeImmutable( '2026-01-01 22:30:00 UTC' );
		self::assertSame( '2026-01-02 00:00:00 +02:00', $period->start( $now, $zone )->format( 'Y-m-d H:i:s P' ) );
		self::assertSame( '2026-01-01 00:00:00 +02:00', $period->previousStart( $now, $zone )->format( 'Y-m-d H:i:s P' ) );
	}

	public function test_previous_day_uses_calendar_across_dst(): void {
		$period = new RankingSnapshotPeriod();
		$zone   = new DateTimeZone( 'Europe/Berlin' );
		$now    = new DateTimeImmutable( '2026-03-30 12:00:00', $zone );
		$start  = $period->start( $now, $zone );
		$before = $period->previousStart( $now, $zone );
		self::assertSame( '2026-03-29 00:00:00 +01:00', $before->format( 'Y-m-d H:i:s P' ) );
		self::assertSame( 23 * HOUR_IN_SECONDS, $start->getTimestamp() - $before->getTimestamp() );
	}

	public function test_capture_window_respects_midnight_and_dst(): void {
		$period = new RankingSnapshotPeriod();
		$zone = new DateTimeZone( 'Europe/Berlin' );
		foreach ( array( '2026-03-29', '2026-10-25', '2027-01-01' ) as $day ) {
			self::assertTrue( $period->inCaptureWindow( new DateTimeImmutable( "$day 00:00:00", $zone ), $zone ) );
			self::assertTrue( $period->inCaptureWindow( new DateTimeImmutable( "$day 00:30:00", $zone ), $zone ) );
			self::assertFalse( $period->inCaptureWindow( new DateTimeImmutable( "$day 00:30:01", $zone ), $zone ) );
		}
	}
}
