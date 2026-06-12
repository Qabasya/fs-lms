<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum AuditTargetType: string {
	case StudentRecord = 'student_record';
	case Application   = 'application';
	case Person        = 'person';
	case User          = 'user';
}
