<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum TableName: string {

	case Persons        = 'fs_lms_persons';
	case Applications   = 'fs_lms_applications';
	case Relationships  = 'fs_lms_relationships';
	case Enrollments    = 'fs_lms_enrollments';
	case Consents       = 'fs_lms_consents';
	case AuditLog        = 'fs_lms_audit_log';
	case PiiAccessLog    = 'fs_lms_pii_access_log';
	case ExpelledArchive = 'fs_lms_expelled_archive';

	public function prefixed(): string {
		global $wpdb;
		return $wpdb->prefix . $this->value;
	}
}