-- ==================== wp_fs_lms_applications ====================
       Table: wp_fs_lms_applications
Create Table: CREATE TABLE `wp_fs_lms_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` int(10) unsigned DEFAULT NULL,
  `parent_person_id` int(10) unsigned DEFAULT NULL,
  `period_key` varchar(50) NOT NULL,
  `status` enum('pending_parent','ready_for_review','enrolling','converted','expired','trash') NOT NULL,
  `join_code_hash` char(64) DEFAULT NULL,
  `join_code_enc` blob DEFAULT NULL,
  `join_code_expires_at` datetime DEFAULT NULL,
  `student_email_hash` char(64) DEFAULT NULL,
  `student_data_enc` longblob DEFAULT NULL,
  `parent_data_enc` longblob DEFAULT NULL,
  `converted_to_enrollment_id` bigint(20) unsigned DEFAULT NULL,
  `parent_submitted_ip` varbinary(16) DEFAULT NULL,
  `parent_submitted_ua` varchar(500) DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `subject_key` varchar(50) DEFAULT NULL,
  `converted_record_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_person_id` (`student_person_id`),
  KEY `parent_person_id` (`parent_person_id`),
  KEY `status` (`status`),
  KEY `join_code_hash` (`join_code_hash`),
  KEY `student_email_hash` (`student_email_hash`),
  KEY `subject_key` (`subject_key`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_assessment_answers ====================
       Table: wp_fs_lms_assessment_answers
Create Table: CREATE TABLE `wp_fs_lms_assessment_answers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `attempt_id` int(10) unsigned NOT NULL,
  `task_id` bigint(20) unsigned NOT NULL,
  `answer_text` longtext DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `score` decimal(6,2) DEFAULT NULL,
  `max_score` decimal(6,2) DEFAULT NULL,
  `grader_note` text DEFAULT NULL,
  `graded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `criteria_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`criteria_scores`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt_task` (`attempt_id`,`task_id`),
  KEY `attempt_id` (`attempt_id`),
  KEY `task_id` (`task_id`)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_assessment_attempts ====================
       Table: wp_fs_lms_assessment_attempts
Create Table: CREATE TABLE `wp_fs_lms_assessment_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assessment_id` bigint(20) unsigned NOT NULL,
  `student_person_id` int(10) unsigned NOT NULL,
  `group_id` smallint(5) unsigned DEFAULT NULL,
  `attempt_number` smallint(5) unsigned NOT NULL,
  `started_at` datetime NOT NULL,
  `deadline_at` datetime NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `status` enum('in_progress','submitted','graded','expired') NOT NULL DEFAULT 'in_progress',
  `total_score` decimal(6,2) DEFAULT NULL,
  `max_score` decimal(6,2) DEFAULT NULL,
  `graded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `group_lesson_id` int(10) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attempt` (`assessment_id`,`student_person_id`,`attempt_number`),
  KEY `assessment_id` (`assessment_id`),
  KEY `student_person_id` (`student_person_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_attendance ====================
       Table: wp_fs_lms_attendance
Create Table: CREATE TABLE `wp_fs_lms_attendance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_lesson_id` int(10) unsigned NOT NULL,
  `student_person_id` bigint(20) unsigned NOT NULL,
  `is_present` tinyint(1) NOT NULL DEFAULT 1,
  `marked_by` bigint(20) unsigned DEFAULT NULL,
  `marked_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lesson_student` (`group_lesson_id`,`student_person_id`),
  KEY `student_person_id` (`student_person_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_audit_log ====================
       Table: wp_fs_lms_audit_log
Create Table: CREATE TABLE `wp_fs_lms_audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  `details_json` longtext DEFAULT NULL,
  `actor_ip` varchar(45) NOT NULL,
  `actor_ua` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `action` (`action`),
  KEY `target_combined` (`target_type`,`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_auth_log ====================
       Table: wp_fs_lms_auth_log
Create Table: CREATE TABLE `wp_fs_lms_auth_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login_identifier` varchar(255) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `result` varchar(10) NOT NULL,
  `actor_ip` varchar(45) NOT NULL,
  `actor_ua` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `action` (`action`),
  KEY `result` (`result`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_consent_change_log ====================
       Table: wp_fs_lms_consent_change_log
Create Table: CREATE TABLE `wp_fs_lms_consent_change_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `person_id` int(10) unsigned DEFAULT NULL,
  `consent_type` varchar(50) NOT NULL,
  `old_hash` varchar(64) DEFAULT NULL,
  `new_hash` varchar(64) DEFAULT NULL,
  `actor_ip` varchar(45) DEFAULT NULL,
  `actor_ua` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `person_id` (`person_id`),
  KEY `consent_type` (`consent_type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_consents ====================
       Table: wp_fs_lms_consents
Create Table: CREATE TABLE `wp_fs_lms_consents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned DEFAULT NULL,
  `person_id` int(10) unsigned DEFAULT NULL,
  `subject_role` varchar(20) NOT NULL,
  `consent_type` varchar(50) NOT NULL,
  `version` varchar(20) NOT NULL,
  `document_hash` varchar(64) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `accepted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `valid_until` datetime DEFAULT NULL,
  `withdrawn_at` datetime DEFAULT NULL,
  `withdrawn_reason` text DEFAULT NULL,
  `signed_for_person_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `person_id` (`person_id`),
  KEY `consent_type` (`consent_type`),
  KEY `signed_for_person_id` (`signed_for_person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_data_change_log ====================
       Table: wp_fs_lms_data_change_log
Create Table: CREATE TABLE `wp_fs_lms_data_change_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `target_person_id` int(10) unsigned NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `old_value_enc` blob DEFAULT NULL,
  `new_value_enc` blob DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `target_person_id` (`target_person_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_email_log ====================
       Table: wp_fs_lms_email_log
Create Table: CREATE TABLE `wp_fs_lms_email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `email_type` varchar(50) NOT NULL,
  `target_person_id` int(10) unsigned DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email_type` (`email_type`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_entity_audit_log ====================
       Table: wp_fs_lms_entity_audit_log
Create Table: CREATE TABLE `wp_fs_lms_entity_audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `operation` varchar(10) NOT NULL,
  `entity_type` varchar(30) NOT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `old_label` varchar(255) DEFAULT NULL,
  `actor_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `operation` (`operation`),
  KEY `entity_combined` (`entity_type`,`entity_id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_export_log ====================
       Table: wp_fs_lms_export_log
Create Table: CREATE TABLE `wp_fs_lms_export_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `operation_type` varchar(10) NOT NULL DEFAULT 'export',
  `data_type` varchar(50) NOT NULL,
  `action_type` varchar(20) NOT NULL,
  `target_ids_json` text DEFAULT NULL,
  `actor_ip` varchar(45) NOT NULL DEFAULT '',
  `actor_ua` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `data_type` (`data_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_group_lessons ====================
       Table: wp_fs_lms_group_lessons
Create Table: CREATE TABLE `wp_fs_lms_group_lessons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` smallint(5) unsigned NOT NULL,
  `lesson_id` bigint(20) unsigned DEFAULT NULL,
  `label` varchar(150) DEFAULT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `work_ids_snapshot` longtext DEFAULT NULL,
  `extra_work_ids` longtext DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `teacher_user_id` bigint(20) unsigned DEFAULT NULL,
  `kind` enum('group','individual') NOT NULL DEFAULT 'group',
  `status` enum('scheduled','held','cancelled','moved') NOT NULL DEFAULT 'scheduled',
  `student_person_id` int(10) unsigned DEFAULT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `continued_from_id` int(10) unsigned DEFAULT NULL,
  `visibility` enum('hidden','open','archived') NOT NULL DEFAULT 'hidden',
  `opened_at` datetime DEFAULT NULL,
  `homework_due_at` datetime DEFAULT NULL,
  `allow_late` tinyint(1) NOT NULL DEFAULT 1,
  `work_deadlines` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`work_deadlines`)),
  `recording_url` varchar(1000) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `step_settings_overrides` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`step_settings_overrides`)),
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `lesson_id` (`lesson_id`),
  KEY `group_position` (`group_id`,`position`),
  KEY `kind_student` (`kind`,`student_person_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_groups ====================
       Table: wp_fs_lms_groups
Create Table: CREATE TABLE `wp_fs_lms_groups` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `subject_key` varchar(50) NOT NULL,
  `academic_period_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `teacher_id` int(10) unsigned DEFAULT NULL,
  `meetings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meetings`)),
  `program_locked_at` datetime DEFAULT NULL,
  `access_mode` enum('scheduled','open') NOT NULL DEFAULT 'scheduled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `course_id` bigint(20) unsigned DEFAULT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject_key` (`subject_key`),
  KEY `academic_period_id` (`academic_period_id`),
  KEY `teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_learning_events ====================
       Table: wp_fs_lms_learning_events
Create Table: CREATE TABLE `wp_fs_lms_learning_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject_key` varchar(50) DEFAULT NULL,
  `group_id` smallint(5) unsigned DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `entity_type` varchar(30) DEFAULT NULL,
  `entity_id` varchar(100) DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `group_created` (`group_id`,`created_at`),
  KEY `subject_created` (`subject_key`,`created_at`),
  KEY `actor_user_id` (`actor_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_lesson_progress ====================
       Table: wp_fs_lms_lesson_progress
Create Table: CREATE TABLE `wp_fs_lms_lesson_progress` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` int(10) unsigned NOT NULL,
  `group_lesson_id` int(10) unsigned NOT NULL,
  `lesson_id` bigint(20) unsigned NOT NULL,
  `step_key` varchar(64) NOT NULL,
  `status` enum('locked','available','viewed','completed','failed') NOT NULL DEFAULT 'locked',
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `step` (`student_person_id`,`group_lesson_id`,`step_key`),
  KEY `group_lesson_id` (`group_lesson_id`),
  KEY `student_lesson` (`student_person_id`,`lesson_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_notifications ====================
       Table: wp_fs_lms_notifications
Create Table: CREATE TABLE `wp_fs_lms_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(40) NOT NULL,
  `group_id` smallint(5) unsigned DEFAULT NULL,
  `entity_type` varchar(30) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `dedupe_key` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL,
  `seen_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recipient_dedupe` (`recipient_user_id`,`dedupe_key`),
  KEY `recipient_created` (`recipient_user_id`,`created_at`),
  KEY `recipient_seen` (`recipient_user_id`,`seen_at`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci

-- ==================== wp_fs_lms_person_documents ====================
       Table: wp_fs_lms_person_documents
Create Table: CREATE TABLE `wp_fs_lms_person_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int(10) unsigned NOT NULL,
  `email_enc` blob DEFAULT NULL,
  `email_hash` char(64) DEFAULT NULL,
  `phone_enc` blob DEFAULT NULL,
  `phone_hash` char(64) DEFAULT NULL,
  `doc_type` varchar(30) DEFAULT NULL,
  `doc_number_enc` blob DEFAULT NULL,
  `doc_number_hash` char(64) DEFAULT NULL,
  `doc_issued_by_enc` blob DEFAULT NULL,
  `doc_issued_date` date DEFAULT NULL,
  `inn_enc` blob DEFAULT NULL,
  `inn_hash` char(64) DEFAULT NULL,
  `address_enc` blob DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_id` (`person_id`),
  KEY `email_hash` (`email_hash`),
  KEY `phone_hash` (`phone_hash`),
  KEY `doc_number_hash` (`doc_number_hash`),
  KEY `inn_hash` (`inn_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_persons ====================
       Table: wp_fs_lms_persons
Create Table: CREATE TABLE `wp_fs_lms_persons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `wp_user_id` bigint(20) unsigned DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `is_student` tinyint(1) NOT NULL DEFAULT 0,
  `school` varchar(255) DEFAULT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `expelled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `wp_user_id` (`wp_user_id`),
  KEY `is_student` (`is_student`),
  KEY `expelled_at` (`expelled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_pii_access_log ====================
       Table: wp_fs_lms_pii_access_log
Create Table: CREATE TABLE `wp_fs_lms_pii_access_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned NOT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `person_id` int(10) unsigned NOT NULL,
  `fields_accessed` text NOT NULL,
  `access_reason` varchar(255) NOT NULL,
  `actor_ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `actor_ua` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `actor_user_id` (`actor_user_id`),
  KEY `person_id` (`person_id`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_rooms ====================
       Table: wp_fs_lms_rooms
Create Table: CREATE TABLE `wp_fs_lms_rooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `seats` smallint(5) unsigned NOT NULL DEFAULT 0,
  `allowed_subjects` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_student_records ====================
       Table: wp_fs_lms_student_records
Create Table: CREATE TABLE `wp_fs_lms_student_records` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` int(10) unsigned NOT NULL,
  `parent_person_id` int(10) unsigned NOT NULL,
  `group_id` smallint(5) unsigned NOT NULL,
  `snapshot_last_name` varchar(100) NOT NULL DEFAULT '',
  `snapshot_first_name` varchar(100) NOT NULL DEFAULT '',
  `snapshot_middle_name` varchar(100) DEFAULT NULL,
  `snapshot_school` varchar(255) DEFAULT NULL,
  `snapshot_grade` varchar(10) DEFAULT NULL,
  `contract_no` varchar(50) DEFAULT NULL,
  `contract_date` date DEFAULT NULL,
  `order_no` varchar(50) DEFAULT NULL,
  `order_date` date DEFAULT NULL,
  `status` enum('active','finished','expelled','transferred') NOT NULL DEFAULT 'active',
  `enrolled_at` datetime NOT NULL,
  `enrolled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `expelled_at` datetime DEFAULT NULL,
  `expelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `expel_reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_person_id` (`student_person_id`),
  KEY `parent_person_id` (`parent_person_id`),
  KEY `group_id` (`group_id`),
  KEY `status` (`status`),
  KEY `enrolled_at` (`enrolled_at`),
  KEY `expelled_at` (`expelled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_submissions ====================
       Table: wp_fs_lms_submissions
Create Table: CREATE TABLE `wp_fs_lms_submissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` int(10) unsigned NOT NULL,
  `group_lesson_id` int(10) unsigned NOT NULL,
  `work_id` bigint(20) unsigned NOT NULL,
  `work_type` enum('practice','independent','homework') NOT NULL,
  `task_id` bigint(20) unsigned DEFAULT NULL,
  `answer_text` longtext DEFAULT NULL,
  `attachment_id` bigint(20) unsigned DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `status` enum('assigned','submitted','pending_review','graded','returned') NOT NULL DEFAULT 'assigned',
  `score` decimal(6,2) DEFAULT NULL,
  `max_score` decimal(6,2) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `graded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_person_id` (`student_person_id`),
  KEY `group_lesson_id` (`group_lesson_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_substitutions ====================
       Table: wp_fs_lms_substitutions
Create Table: CREATE TABLE `wp_fs_lms_substitutions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` smallint(5) unsigned NOT NULL,
  `original_teacher_id` bigint(20) unsigned DEFAULT NULL,
  `substitute_teacher_id` bigint(20) unsigned NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`),
  KEY `substitute_teacher_id` (`substitute_teacher_id`),
  KEY `validity` (`group_id`,`valid_from`,`valid_to`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

-- ==================== wp_fs_lms_task_attempts ====================
       Table: wp_fs_lms_task_attempts
Create Table: CREATE TABLE `wp_fs_lms_task_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `student_person_id` int(10) unsigned NOT NULL,
  `group_lesson_id` int(10) unsigned NOT NULL,
  `step_key` varchar(64) NOT NULL,
  `task_id` bigint(20) unsigned NOT NULL,
  `attempt_number` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `answer` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answer`)),
  `is_correct` tinyint(1) DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `max_score` decimal(5,2) DEFAULT NULL,
  `item_feedback` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`item_feedback`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_step` (`student_person_id`,`group_lesson_id`,`step_key`),
  KEY `task_id` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci

