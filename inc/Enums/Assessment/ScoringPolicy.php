<?php

declare( strict_types=1 );

namespace Inc\Enums\Assessment;

enum ScoringPolicy: string {
	case Highest = 'highest';
	case Last    = 'last';
	case First   = 'first';
}
