<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\TableName;

/**
 * Class Migration_1_0_0
 *
 * Монолитная миграция. Разворачивает актуальную схему из 9 таблиц.
 *
 * @package Inc\Migrations
 *
 * ### Таблицы:
 *
 * - **persons**          — идентификация (ФИО plain, роль, дата рождения)
 * - **person_documents** — весь PII зашифрован (email, phone, doc, inn, address)
 * - **groups**           — группы; заменяет матрицу из wp_options
 * - **applications**     — заявки на обучение (двухэтапный OTP-флоу)
 * - **enrollments**      — зачисления; group_key → groups.group_key
 * - **archive**          — запись создаётся при зачислении (expelled_at=NULL); связь родитель→ученик
 * - **consents**         — согласия на обработку ПДн
 * - **audit_log**        — журнал действий
 * - **pii_access_log**   — журнал доступа к PII
 */
class Migration_1_0_0 implements MigrationInterface {

	public function __construct() {}

	public function up(): void {
		global $wpdb;

		$cc = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// ===== Сброс старой схемы =====
		// Таблицы с изменившейся структурой и удалённые таблицы дропаем явно —
		// dbDelta умеет добавлять колонки, но не удалять и не менять типы.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array(
			$wpdb->prefix . 'fs_lms_expelled_archive',
			$wpdb->prefix . 'fs_lms_relationships',
			$wpdb->prefix . 'fs_lms_enrollments',
			$wpdb->prefix . 'fs_lms_persons',
		) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$t`" );
		}
		$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name = 'fs_lms_student_group_matrix'" );
		// phpcs:enable

		// ===== 1. persons — идентификация (нечувствительные данные) =====
		$persons = TableName::Persons->prefixed();
		dbDelta(
			"CREATE TABLE $persons (
			id         int unsigned             NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned      DEFAULT NULL,
			full_name  varchar(255)             NOT NULL DEFAULT '',
			birth_date date                     DEFAULT NULL,
			role       enum('student','parent') NOT NULL,
			deleted_at datetime                 DEFAULT NULL,
			created_at datetime                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime                 NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wp_user_id (wp_user_id),
			KEY role (role)
		) $cc;"
		);

		// ===== 2. person_documents — весь PII =====
		$person_documents = TableName::PersonDocuments->prefixed();
		dbDelta(
			"CREATE TABLE $person_documents (
			id                int unsigned NOT NULL AUTO_INCREMENT,
			person_id         int unsigned NOT NULL,
			email_enc         blob         DEFAULT NULL,
			email_hash        char(64)     DEFAULT NULL,
			phone_enc         blob         DEFAULT NULL,
			phone_hash        char(64)     DEFAULT NULL,
			doc_type          varchar(30)  DEFAULT NULL,
			doc_number_enc    blob         DEFAULT NULL,
			doc_number_hash   char(64)     DEFAULT NULL,
			doc_issued_by_enc blob         DEFAULT NULL,
			doc_issued_date   date         DEFAULT NULL,
			inn_enc           blob         DEFAULT NULL,
			inn_hash          char(64)     DEFAULT NULL,
			address_enc       blob         DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY person_id (person_id),
			KEY email_hash (email_hash),
			KEY phone_hash (phone_hash),
			KEY doc_number_hash (doc_number_hash),
			KEY inn_hash (inn_hash)
		) $cc;"
		);

		// ===== 3. groups — группы (заменяет матрицу wp_options) =====
		$groups = TableName::Groups->prefixed();
		dbDelta(
			"CREATE TABLE $groups (
			id          smallint unsigned NOT NULL AUTO_INCREMENT,
			group_key   varchar(100)      NOT NULL,
			subject_key varchar(50)       NOT NULL,
			period_key  varchar(50)       NOT NULL,
			name        varchar(255)      DEFAULT NULL,
			schedule    varchar(500)      DEFAULT NULL,
			created_at  datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY group_key (group_key),
			KEY subject_key (subject_key),
			KEY period_key (period_key)
		) $cc;"
		);

		// ===== 4. applications — заявки на обучение =====
		$applications = TableName::Applications->prefixed();
		dbDelta(
			"CREATE TABLE $applications (
			id                         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_person_id          bigint(20) unsigned DEFAULT NULL,
			parent_person_id           bigint(20) unsigned DEFAULT NULL,
			period_key                 varchar(50)         NOT NULL,
			status                     varchar(50)         NOT NULL,
			join_code_hash             varchar(64)         DEFAULT NULL,
			join_code_enc              blob                DEFAULT NULL,
			join_code_expires_at       datetime            DEFAULT NULL,
			student_email_hash         varchar(64)         DEFAULT NULL,
			student_data_enc           longblob            DEFAULT NULL,
			parent_data_enc            longblob            DEFAULT NULL,
			converted_to_enrollment_id bigint(20) unsigned DEFAULT NULL,
			parent_submitted_ip        varchar(45)         DEFAULT NULL,
			parent_submitted_ua        varchar(500)        DEFAULT NULL,
			reviewed_by_user_id        bigint(20) unsigned DEFAULT NULL,
			created_at                 datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at                 datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY student_person_id (student_person_id),
			KEY parent_person_id (parent_person_id),
			KEY status (status),
			KEY join_code_hash (join_code_hash),
			KEY student_email_hash (student_email_hash)
		) $cc;"
		);

		// ===== 5. enrollments — зачисления =====
		$enrollments = TableName::Enrollments->prefixed();
		dbDelta(
			"CREATE TABLE $enrollments (
			id                    int unsigned NOT NULL AUTO_INCREMENT,
			student_person_id     int unsigned NOT NULL,
			source_application_id int unsigned DEFAULT NULL,
			group_key             varchar(100) DEFAULT NULL,
			status                varchar(50)  NOT NULL,
			enrolled_at           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			terminated_at         datetime     DEFAULT NULL,
			terminated_reason     text         DEFAULT NULL,
			terminated_by_user_id int unsigned DEFAULT NULL,
			snapshot_enc          longblob     DEFAULT NULL,
			created_at            datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at            datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY student_person_id (student_person_id),
			KEY source_application_id (source_application_id),
			KEY group_key (group_key),
			KEY status (status)
		) $cc;"
		);

		// ===== 6. archive — зачисления + отчисления + связь родитель→ученик =====
		$archive = TableName::Archive->prefixed();
		dbDelta(
			"CREATE TABLE $archive (
			id                  int unsigned        NOT NULL AUTO_INCREMENT,
			enrollment_id       int unsigned        DEFAULT NULL,
			student_person_id   int unsigned        NOT NULL,
			parent_person_id    int unsigned        NOT NULL,
			contract_no         varchar(50)         DEFAULT NULL,
			contract_date       date                DEFAULT NULL,
			order_no            varchar(50)         DEFAULT NULL,
			order_date          date                DEFAULT NULL,
			group_key           varchar(100)        DEFAULT NULL,
			enrolled_at         datetime            NOT NULL,
			expelled_at         datetime            DEFAULT NULL,
			expelled_by_user_id bigint(20) unsigned DEFAULT NULL,
			reason              varchar(500)        DEFAULT NULL,
			created_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY enrollment_id (enrollment_id),
			KEY student_person_id (student_person_id),
			KEY parent_person_id (parent_person_id),
			KEY group_key (group_key),
			KEY expelled_at (expelled_at)
		) $cc;"
		);

		// ===== 7. consents — согласия на обработку ПДн =====
		$consents = TableName::Consents->prefixed();
		dbDelta(
			"CREATE TABLE $consents (
			id                   bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id       bigint(20) unsigned DEFAULT NULL,
			person_id            bigint(20) unsigned DEFAULT NULL,
			subject_role         varchar(20)         NOT NULL,
			consent_type         varchar(50)         NOT NULL,
			version              varchar(20)         NOT NULL,
			document_hash        varchar(64)         NOT NULL DEFAULT '',
			ip_address           varchar(45)         NOT NULL,
			user_agent           varchar(500)        NOT NULL DEFAULT '',
			accepted_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			valid_until          datetime            DEFAULT NULL,
			withdrawn_at         datetime            DEFAULT NULL,
			withdrawn_reason     text                DEFAULT NULL,
			signed_for_person_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY person_id (person_id),
			KEY consent_type (consent_type),
			KEY signed_for_person_id (signed_for_person_id)
		) $cc;"
		);

		// ===== 8. audit_log — журнал действий =====
		$audit_log = TableName::AuditLog->prefixed();
		dbDelta(
			"CREATE TABLE $audit_log (
			id            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			actor_role    varchar(50)         DEFAULT NULL,
			action        varchar(100)        NOT NULL,
			target_type   varchar(50)         DEFAULT NULL,
			target_id     bigint(20) unsigned DEFAULT NULL,
			details_json  longtext            DEFAULT NULL,
			actor_ip      varchar(45)         NOT NULL,
			actor_ua      text                DEFAULT NULL,
			created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY action (action),
			KEY target_combined (target_type, target_id)
		) $cc;"
		);

		// ===== 9. pii_access_log — журнал доступа к PII =====
		$pii_access_log = TableName::PiiAccessLog->prefixed();
		dbDelta(
			"CREATE TABLE $pii_access_log (
			id             bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_user_id  bigint(20) unsigned NOT NULL,
			actor_role     varchar(50)         DEFAULT NULL,
			person_id      bigint(20) unsigned NOT NULL,
			fields_accessed text               NOT NULL,
			access_reason  varchar(255)        NOT NULL,
			actor_ip       varchar(45)         NOT NULL,
			created_at     datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY person_id (person_id)
		) $cc;"
		);
	}

	public function down(): void {
		global $wpdb;

		$tables = array(
			TableName::Archive->prefixed(),
			TableName::PiiAccessLog->prefixed(),
			TableName::AuditLog->prefixed(),
			TableName::Consents->prefixed(),
			TableName::Enrollments->prefixed(),
			TableName::Applications->prefixed(),
			TableName::PersonDocuments->prefixed(),
			TableName::Groups->prefixed(),
			TableName::Persons->prefixed(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS $table;" );
		}
	}

	public function version(): string {
		return '1.0.0';
	}
}
