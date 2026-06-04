<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\ClockInterface;

class WpClock implements ClockInterface {

	public function now( string $type = 'mysql', bool $gmt = false ): string {
		return current_time( $type, $gmt );
	}
}
