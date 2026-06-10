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
 * - **enrollments**      — зачисления; group_id → groups.id
 * - **archive**          — запись создаётся при зачислении (expelled_at=NULL); связь родитель→ученик
 * - **consents**         — согласия на обработку ПДн
 * - **audit_log**          — журнал действий (зачисление)
 * - **pii_access_log**     — журнал доступа к PII
 * - **export_log**         — журнал экспорта CSV
 * - **data_change_log**    — журнал изменений данных (PII зашифрован)
 * - **consent_change_log** — журнал изменений согласий
 * - **email_log**          — журнал отправки писем
 * - **deletion_log**       — GDPR-журнал жёстких удалений
 * - **auth_log**           — журнал аутентификации
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array(
			$wpdb->prefix . 'fs_lms_expelled_archive',
			$wpdb->prefix . 'fs_lms_relationships',
			$wpdb->prefix . 'fs_lms_enrollments',
			$wpdb->prefix . 'fs_lms_archive',
			$wpdb->prefix . 'fs_lms_student_records',
			$wpdb->prefix . 'fs_lms_persons',
			$wpdb->prefix . 'fs_lms_groups',
		) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `$t`" );
		}
		$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE option_name = 'fs_lms_student_group_matrix'" );
		// phpcs:enable

		// ===== 1. persons — идентификация (нечувствительные данные) =====
		$persons = TableName::Persons->prefixed();
		dbDelta(
			"CREATE TABLE $persons (
			id          int unsigned        NOT NULL AUTO_INCREMENT,
			wp_user_id  bigint(20) unsigned DEFAULT NULL,
			last_name   varchar(100)        NOT NULL,
			first_name  varchar(100)        NOT NULL,
			middle_name varchar(100)        DEFAULT NULL,
			birth_date  date                DEFAULT NULL,
			is_student  tinyint(1)          NOT NULL DEFAULT 0,
			school      varchar(255)        DEFAULT NULL,
			grade       varchar(10)         DEFAULT NULL,
			expelled_at datetime            DEFAULT NULL,
			created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wp_user_id (wp_user_id),
			KEY is_student (is_student),
			KEY expelled_at (expelled_at)
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
			id                 smallint unsigned NOT NULL AUTO_INCREMENT,
			subject_key        varchar(50)       NOT NULL,
			academic_period_id varchar(50)       NOT NULL,
			name               varchar(255)      NOT NULL,
			teacher_id         int unsigned      DEFAULT NULL,
			schedule           text              DEFAULT NULL,
			created_at         datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at         datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			deleted_at         datetime          DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY subject_key (subject_key),
			KEY academic_period_id (academic_period_id),
			KEY teacher_id (teacher_id)
		) $cc;"
		);

		// ===== 4. applications — заявки на обучение =====
		$applications = TableName::Applications->prefixed();
		dbDelta(
			"CREATE TABLE $applications (
			id                   int unsigned                                                              NOT NULL AUTO_INCREMENT,
			student_person_id    int unsigned                                                              DEFAULT NULL,
			parent_person_id     int unsigned                                                              DEFAULT NULL,
			status               enum('pending_parent','ready_for_review','enrolling','converted','expired','trash') NOT NULL,
			join_code_hash       char(64)                                                                  DEFAULT NULL,
			join_code_enc        blob                                                                      DEFAULT NULL,
			join_code_expires_at datetime                                                                  DEFAULT NULL,
			student_email_hash   char(64)                                                                  DEFAULT NULL,
			student_data_enc     longblob                                                                  DEFAULT NULL,
			parent_data_enc      longblob                                                                  DEFAULT NULL,
			converted_record_id  int unsigned                                                              DEFAULT NULL,
			parent_submitted_ip  varbinary(16)                                                             DEFAULT NULL,
			parent_submitted_ua  varchar(500)                                                              DEFAULT NULL,
			reviewed_by_user_id  bigint(20) unsigned                                                       DEFAULT NULL,
			created_at           datetime                                                                  NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at           datetime                                                                  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY student_person_id (student_person_id),
			KEY parent_person_id (parent_person_id),
			KEY status (status),
			KEY join_code_hash (join_code_hash),
			KEY student_email_hash (student_email_hash)
		) $cc;"
		);

		// ===== 5. student_records — факт обучения (зачисление + отчисление + связь родитель→ученик) =====
		$student_records = TableName::StudentRecords->prefixed();
		dbDelta(
			"CREATE TABLE $student_records (
			id                  int unsigned        NOT NULL AUTO_INCREMENT,
			student_person_id   int unsigned        NOT NULL,
			parent_person_id    int unsigned        NOT NULL,
			group_id             smallint unsigned   NOT NULL,
			snapshot_last_name   varchar(100)        NOT NULL DEFAULT '',
			snapshot_first_name  varchar(100)        NOT NULL DEFAULT '',
			snapshot_middle_name varchar(100)        DEFAULT NULL,
			snapshot_school      varchar(255)        DEFAULT NULL,
			snapshot_grade       varchar(10)         DEFAULT NULL,
			contract_no          varchar(50)         DEFAULT NULL,
			contract_date       date                DEFAULT NULL,
			order_no            varchar(50)         DEFAULT NULL,
			order_date          date                DEFAULT NULL,
			status              enum('active','finished','expelled','transferred') NOT NULL DEFAULT 'active',
			enrolled_at         datetime            NOT NULL,
			enrolled_by_user_id bigint(20) unsigned DEFAULT NULL,
			expelled_at         datetime            DEFAULT NULL,
			expelled_by_user_id bigint(20) unsigned DEFAULT NULL,
			expel_reason        varchar(500)        DEFAULT NULL,
			created_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY student_person_id (student_person_id),
			KEY parent_person_id (parent_person_id),
			KEY group_id (group_id),
			KEY status (status),
			KEY enrolled_at (enrolled_at),
			KEY expelled_at (expelled_at)
		) $cc;"
		);

		// ===== 6. consents — согласия на обработку ПДн =====
		$consents = TableName::Consents->prefixed();
		dbDelta(
			"CREATE TABLE $consents (
			id                   int unsigned        NOT NULL AUTO_INCREMENT,
			application_id       int unsigned        DEFAULT NULL,
			person_id            int unsigned        DEFAULT NULL,
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
			signed_for_person_id int unsigned        DEFAULT NULL,
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
			id            int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			actor_role    varchar(50)         DEFAULT NULL,
			action        varchar(100)        NOT NULL,
			target_type   varchar(50)         DEFAULT NULL,
			target_id     int unsigned        DEFAULT NULL,
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
			id             int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id  bigint(20) unsigned NOT NULL,
			actor_role     varchar(50)         DEFAULT NULL,
			person_id      int unsigned        NOT NULL,
			fields_accessed text               NOT NULL,
			access_reason  varchar(255)        NOT NULL,
			actor_ip       varchar(45)         NOT NULL,
			actor_ua       text                DEFAULT NULL,
			created_at     datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY person_id (person_id)
		) $cc;"
		);

		// ===== 10. export_log — журнал экспорта CSV =====
		$export_log = TableName::ExportLog->prefixed();
		dbDelta(
			"CREATE TABLE $export_log (
			id             int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id  bigint(20) unsigned NOT NULL,
			actor_role     varchar(50)         DEFAULT NULL,
			data_type      varchar(50)         NOT NULL,
			action_type    varchar(20)         NOT NULL,
			target_ids_json text               DEFAULT NULL,
			created_at     datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY data_type (data_type)
		) $cc;"
		);

		// ===== 11. data_change_log — журнал изменений данных (PII зашифрован) =====
		$data_change_log = TableName::DataChangeLog->prefixed();
		dbDelta(
			"CREATE TABLE $data_change_log (
			id               int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id    bigint(20) unsigned NOT NULL,
			actor_role       varchar(50)         DEFAULT NULL,
			target_person_id int unsigned        NOT NULL,
			field_name       varchar(100)        NOT NULL,
			old_value_enc    blob                DEFAULT NULL,
			new_value_enc    blob                DEFAULT NULL,
			created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY target_person_id (target_person_id)
		) $cc;"
		);

		// ===== 12. consent_change_log — журнал изменений согласий =====
		$consent_change_log = TableName::ConsentChangeLog->prefixed();
		dbDelta(
			"CREATE TABLE $consent_change_log (
			id            int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			actor_role    varchar(50)         DEFAULT NULL,
			person_id     int unsigned        DEFAULT NULL,
			consent_type  varchar(50)         NOT NULL,
			old_hash      varchar(64)         DEFAULT NULL,
			new_hash      varchar(64)         DEFAULT NULL,
			created_at    datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY person_id (person_id),
			KEY consent_type (consent_type)
		) $cc;"
		);

		// ===== 13. email_log — журнал отправки писем =====
		$email_log = TableName::EmailLog->prefixed();
		dbDelta(
			"CREATE TABLE $email_log (
			id               int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id    bigint(20) unsigned DEFAULT NULL,
			actor_role       varchar(50)         DEFAULT NULL,
			email_type       varchar(50)         NOT NULL,
			target_person_id int unsigned        DEFAULT NULL,
			status           varchar(10)         NOT NULL,
			error_message    text                DEFAULT NULL,
			created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY email_type (email_type),
			KEY status (status)
		) $cc;"
		);

		// ===== 14. deletion_log — GDPR-журнал жёстких удалений =====
		$deletion_log = TableName::DeletionLog->prefixed();
		dbDelta(
			"CREATE TABLE $deletion_log (
			id                int unsigned        NOT NULL AUTO_INCREMENT,
			actor_user_id     bigint(20) unsigned NOT NULL,
			actor_role        varchar(50)         DEFAULT NULL,
			entity_type       varchar(50)         NOT NULL,
			entity_id         int unsigned        NOT NULL,
			cascaded_summary  text                DEFAULT NULL,
			actor_ip          varchar(45)         NOT NULL,
			created_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY entity_type (entity_type)
		) $cc;"
		);

		// ===== 15. auth_log — журнал аутентификации =====
		$auth_log = TableName::AuthLog->prefixed();
		dbDelta(
			"CREATE TABLE $auth_log (
			id               int unsigned NOT NULL AUTO_INCREMENT,
			login_identifier varchar(255) DEFAULT NULL,
			action           varchar(50)  NOT NULL,
			result           varchar(10)  NOT NULL,
			actor_ip         varchar(45)  NOT NULL,
			actor_ua         text         DEFAULT NULL,
			created_at       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY result (result)
		) $cc;"
		);

		// ===== Cleanup — добавление колонок для уже существующих установок =====
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `$student_records`
			ADD COLUMN IF NOT EXISTS `snapshot_last_name`   varchar(100) NOT NULL DEFAULT '',
			ADD COLUMN IF NOT EXISTS `snapshot_first_name`  varchar(100) NOT NULL DEFAULT '',
			ADD COLUMN IF NOT EXISTS `snapshot_middle_name` varchar(100) DEFAULT NULL,
			ADD COLUMN IF NOT EXISTS `snapshot_school`      varchar(255) DEFAULT NULL,
			ADD COLUMN IF NOT EXISTS `snapshot_grade`       varchar(10)  DEFAULT NULL,
			ADD COLUMN IF NOT EXISTS `enrolled_by_user_id`  bigint(20) unsigned DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE `$groups`
			DROP INDEX IF EXISTS `group_id`,
			DROP COLUMN IF EXISTS `group_id`" );
		$persons = TableName::Persons->prefixed();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( $wpdb->get_var( "SHOW COLUMNS FROM `$persons` LIKE 'deleted_at'" ) ) {
			$wpdb->query( "ALTER TABLE `$persons` CHANGE COLUMN `deleted_at` `expelled_at` datetime DEFAULT NULL" );
		}
		$wpdb->query( "ALTER TABLE `$persons` ADD INDEX IF NOT EXISTS `expelled_at` (`expelled_at`)" );
		$wpdb->query( "ALTER TABLE `$pii_access_log` ADD COLUMN IF NOT EXISTS `actor_ua` text DEFAULT NULL" );
		// phpcs:enable
	}

	public function down(): void {
		global $wpdb;

		$tables = array(
			TableName::AuthLog->prefixed(),
			TableName::DeletionLog->prefixed(),
			TableName::EmailLog->prefixed(),
			TableName::ConsentChangeLog->prefixed(),
			TableName::DataChangeLog->prefixed(),
			TableName::ExportLog->prefixed(),
			TableName::StudentRecords->prefixed(),
			TableName::PiiAccessLog->prefixed(),
			TableName::AuditLog->prefixed(),
			TableName::Consents->prefixed(),
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
