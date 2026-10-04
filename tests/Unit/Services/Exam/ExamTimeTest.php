<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\TestCase;

class ExamTimeTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	private function time( string $local = '2026-03-10 12:00:00', string $utc = '2026-03-10 09:00:00' ): ExamTime {
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( static fn( string $type = 'mysql', bool $gmt = false ): string => $gmt ? $utc : $local );

		return new ExamTime( $clock );
	}

	public function test_now_utc_and_local_come_from_clock(): void {
		$time = $this->time();
		self::assertSame( '2026-03-10 09:00:00', $time->nowUtc() );
		self::assertSame( '2026-03-10 12:00:00', $time->nowLocal() );
	}

	public function test_to_utc_and_back_round_trip(): void {
		$GLOBALS['_fs_test_timezone'] = 'Europe/Kaliningrad'; // UTC+2
		$time                         = $this->time();

		self::assertSame( '2026-03-10 08:00:00', $time->toUtc( '2026-03-10 10:00:00' ) );
		self::assertSame( '2026-03-10 10:00:00', $time->toLocal( '2026-03-10 08:00:00' ) );
		self::assertSame( '2026-03-10 10:00:00', $time->toLocal( $time->toUtc( '2026-03-10 10:00:00' ) ) );
	}

	public function test_end_of_local_day_utc(): void {
		$GLOBALS['_fs_test_timezone'] = 'Europe/Kaliningrad';

		self::assertSame( '2026-03-10 21:59:59', $this->time()->endOfLocalDayUtc( '2026-03-10' ) );
	}

	public function test_add_minutes_crosses_midnight(): void {
		self::assertSame( '2026-03-11 00:05:00', $this->time()->addMinutes( '2026-03-10 23:45:00', 20 ) );
	}

	public function test_add_negative_minutes(): void {
		self::assertSame( '2026-03-10 09:30:00', $this->time()->addMinutes( '2026-03-10 10:00:00', -30 ) );
	}
}
