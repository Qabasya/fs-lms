<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum AccessLevel: string {
	case None        = 'none';
	case Read        = 'read';
	case ReadSubmit  = 'read_submit';
}
