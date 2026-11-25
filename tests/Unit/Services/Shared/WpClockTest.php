<?php

declare( strict_types=1 );

namespace Unit\Services\Shared;

use Inc\Services\Shared\WpClock;
use PHPUnit\Framework\TestCase;

class WpClockTest extends TestCase {

	public function test_wordpress_integer_timestamps_are_returned_as_strings(): void {
		$clock = new WpClock();

		self::assertSame( (string) current_time( 'U', true ), $clock->now( 'U', true ) );
		self::assertSame( (string) current_time( 'timestamp', true ), $clock->now( 'timestamp', true ) );
	}
}
