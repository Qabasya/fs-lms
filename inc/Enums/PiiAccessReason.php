<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum PiiAccessReason: string {
	case ApplicationReview     = 'application_review';
	case AdminRevealCredentials = 'admin_reveal_credentials';
}
