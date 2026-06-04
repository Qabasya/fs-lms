<?php

declare( strict_types=1 );

namespace Inc\Contracts;

interface ClockInterface {

	public function now( string $type = 'mysql', bool $gmt = false ): string;
}
