<?php

declare( strict_types=1 );

namespace Inc\Enums\Access;

enum AccessLevel: string {
	case None        = 'none';
	case Read        = 'read';
	case ReadSubmit  = 'read_submit';
}
