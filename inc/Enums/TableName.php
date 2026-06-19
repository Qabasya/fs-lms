<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum TableName: string {

	case Persons         = 'fs_lms_persons';
	case PersonDocuments = 'fs_lms_person_documents';
	case Groups          = 'fs_lms_groups';
	case Applications    = 'fs_lms_applications';
	case StudentRecords  = 'fs_lms_student_records';
	case Consents        = 'fs_lms_consents';
	case EntityAuditLog    = 'fs_lms_entity_audit_log';
	case AuditLog          = 'fs_lms_audit_log';
	case PiiAccessLog      = 'fs_lms_pii_access_log';
	case ExportLog         = 'fs_lms_export_log';
	case DataChangeLog     = 'fs_lms_data_change_log';
	case ConsentChangeLog  = 'fs_lms_consent_change_log';
	case EmailLog          = 'fs_lms_email_log';
	case AuthLog           = 'fs_lms_auth_log';

	// ==== Этап 2 — программа группы ====
	case GroupLessons   = 'fs_lms_group_lessons';
	case LearningEvents = 'fs_lms_learning_events';

	// ==== Этап 3 — сдача работ ====
	case Submissions = 'fs_lms_submissions';

	// ==== Этап 4 — контрольные и экзамены ====
	case AssessmentAttempts = 'fs_lms_assessment_attempts';
	case AssessmentAnswers  = 'fs_lms_assessment_answers';

	// ==== Этап 1.5 — прогресс прохождения шагов (★) ====
	case LessonProgress = 'fs_lms_lesson_progress';

	public function prefixed(): string {
		global $wpdb;
		return $wpdb->prefix . $this->value;
	}
}
