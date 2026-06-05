<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum TableName: string {

	case Persons         = 'fs_lms_persons';
	case PersonDocuments = 'fs_lms_person_documents';
	case Groups          = 'fs_lms_groups';
	case Applications    = 'fs_lms_applications';
	case Enrollments     = 'fs_lms_enrollments';
	case Archive         = 'fs_lms_archive';
	case Consents        = 'fs_lms_consents';
	case AuditLog        = 'fs_lms_audit_log';
	case PiiAccessLog    = 'fs_lms_pii_access_log';

	public function prefixed(): string {
		global $wpdb;
		return $wpdb->prefix . $this->value;
	}
}
