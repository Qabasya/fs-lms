<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\TableName;

/**
 * Class Migration_1_0_0
 *
 * Первая монолитная миграция. Разворачивает базовую схему из 7 реляционных таблиц
 * для полноценной работы системы зачисления.
 *
 * @package Inc\Migrations
 *
 * ### Основные обязанности:
 *
 * 1. **Создание таблиц** — разворачивание всех необходимых таблиц для системы зачисления.
 * 2. **Настройка индексов** — создание индексов для оптимизации запросов.
 * 3. **Откат изменений** — удаление всех созданных таблиц при откате миграции.
 *
 * ### Архитектурная роль:
 *
 * Реализует интерфейс MigrationInterface и управляет версией 1.0.0 схемы БД.
 * Используется в MigrationRunner для инициализации базы данных плагина.
 *
 * ### Таблицы, создаваемые миграцией:
 *
 * - **persons** — персональные данные (ФИО, документы, контакты)
 * - **applications** — заявки на обучение
 * - **relationships** — связи между опекуном и учеником
 * - **enrollments** — зачисления студентов на предметы
 * - **consents** — согласия на обработку персональных данных
 * - **audit_log** — журнал аудита действий
 * - **pii_access_log** — журнал доступа к персональным данным (PII)
 */
class Migration_1_0_0 implements MigrationInterface {

	/**
	 * Конструктор миграции.
	 */
	public function __construct() {}

	/**
	 * Применяет миграцию: создаёт все таблицы базы данных.
	 *
	 * @return void
	 */
	public function up(): void {
		global $wpdb;

		// get_charset_collate() — получает кодировку и collation из конфигурации WordPress
		$cc = $wpdb->get_charset_collate();

		// dbDelta() — функция WordPress для создания/обновления таблиц с проверкой структуры
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// ===== 1. Таблица персональных данных (persons) =====
		$persons = TableName::Persons->prefixed();
		dbDelta(
			"CREATE TABLE $persons (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			email varchar(255) DEFAULT NULL,
			full_name_enc longblob DEFAULT NULL,
			doc_number_enc longblob DEFAULT NULL,
			inn_enc longblob DEFAULT NULL,
			address_enc longblob DEFAULT NULL,
			phone_enc longblob DEFAULT NULL,
			doc_number_hash varchar(64) DEFAULT NULL,
			inn_hash varchar(64) DEFAULT NULL,
			deleted_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wp_user_id (wp_user_id),
			KEY doc_number_hash (doc_number_hash),
			KEY inn_hash (inn_hash),
			KEY email (email)
		) $cc;"
		);

		// ===== 2. Таблица заявок на обучение (applications) =====
		$applications = TableName::Applications->prefixed();
		dbDelta(
			"CREATE TABLE $applications (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_person_id bigint(20) unsigned DEFAULT NULL,
			parent_person_id bigint(20) unsigned DEFAULT NULL,
			period_key varchar(50) NOT NULL,
			status varchar(50) NOT NULL,
			join_code_hash varchar(64) DEFAULT NULL,
			join_code_expires_at datetime DEFAULT NULL,
			student_email_hash varchar(64) DEFAULT NULL,
			student_data_enc longblob DEFAULT NULL,
			parent_data_enc longblob DEFAULT NULL,
			converted_to_enrollment_id bigint(20) unsigned DEFAULT NULL,
			parent_submitted_ip varchar(45) DEFAULT NULL,
			parent_submitted_ua varchar(500) DEFAULT NULL,
			reviewed_by_user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY student_person_id (student_person_id),
			KEY parent_person_id (parent_person_id),
			KEY status (status),
			KEY join_code_hash (join_code_hash),
			KEY student_email_hash (student_email_hash)
		) $cc;"
		);

		// ===== 3. Таблица связей опекун-ученик (relationships) =====
		$relationships = TableName::Relationships->prefixed();
		dbDelta(
			"CREATE TABLE $relationships (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			guardian_person_id bigint(20) unsigned NOT NULL,
			student_person_id bigint(20) unsigned NOT NULL,
			relation_type varchar(50) NOT NULL,
			valid_from date NOT NULL,
			valid_to date DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY guardian_student_from (guardian_person_id, student_person_id, valid_from),
			KEY student_person_id (student_person_id)
		) $cc;"
		);

		// ===== 4. Таблица зачислений (enrollments) =====
		$enrollments = TableName::Enrollments->prefixed();
		dbDelta(
			"CREATE TABLE $enrollments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_person_id bigint(20) unsigned NOT NULL,
			source_application_id bigint(20) unsigned DEFAULT NULL,
			group_id bigint(20) unsigned DEFAULT NULL,
			subject_key varchar(50) NOT NULL,
			period_key varchar(50) NOT NULL,
			status varchar(50) NOT NULL,
			enrolled_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			terminated_at datetime DEFAULT NULL,
			terminated_reason text DEFAULT NULL,
			terminated_by_user_id bigint(20) unsigned DEFAULT NULL,
			snapshot_enc longblob DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY student_subject_period (student_person_id, subject_key, period_key),
			KEY source_application_id (source_application_id),
			KEY group_id (group_id),
			KEY status (status)
		) $cc;"
		);

		// ===== 5. Таблица согласий (consents) =====
		$consents = TableName::Consents->prefixed();
		dbDelta(
			"CREATE TABLE $consents (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned DEFAULT NULL,
			person_id bigint(20) unsigned DEFAULT NULL,
			subject_role varchar(20) NOT NULL,
			consent_type varchar(50) NOT NULL,
			version varchar(20) NOT NULL,
			document_hash varchar(64) NOT NULL DEFAULT '',
			ip_address varchar(45) NOT NULL,
			user_agent varchar(500) NOT NULL DEFAULT '',
			accepted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			valid_until datetime DEFAULT NULL,
			withdrawn_at datetime DEFAULT NULL,
			withdrawn_reason text DEFAULT NULL,
			signed_for_person_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY person_id (person_id),
			KEY consent_type (consent_type),
			KEY signed_for_person_id (signed_for_person_id)
		) $cc;"
		);

		// ===== 6. Таблица аудита действий (audit_log) =====
		$audit_log = TableName::AuditLog->prefixed();
		dbDelta(
			"CREATE TABLE $audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned DEFAULT NULL,
			actor_role varchar(50) DEFAULT NULL,
			action varchar(100) NOT NULL,
			target_type varchar(50) DEFAULT NULL,
			target_id bigint(20) unsigned DEFAULT NULL,
			details_json longtext DEFAULT NULL,
			actor_ip varchar(45) NOT NULL,
			actor_ua text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY action (action),
			KEY target_combined (target_type, target_id)
		) $cc;"
		);

		// ===== 7. Таблица доступа к PII (pii_access_log) =====
		$pii_access_log = TableName::PiiAccessLog->prefixed();
		dbDelta(
			"CREATE TABLE $pii_access_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned NOT NULL,
			actor_role varchar(50) DEFAULT NULL,
			person_id bigint(20) unsigned NOT NULL,
			fields_accessed text NOT NULL,
			access_reason varchar(255) NOT NULL,
			actor_ip varchar(45) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY actor_user_id (actor_user_id),
			KEY person_id (person_id)
		) $cc;"
		);
	}

	/**
	 * Откатывает миграцию: удаляет все созданные таблицы.
	 *
	 * @return void
	 */
	public function down(): void {
		global $wpdb;

		// Таблицы удаляются в обратном порядке (от зависимых к основным)
		$tables = array(
			TableName::PiiAccessLog->prefixed(),
			TableName::AuditLog->prefixed(),
			TableName::Consents->prefixed(),
			TableName::Enrollments->prefixed(),
			TableName::Relationships->prefixed(),
			TableName::Applications->prefixed(),
			TableName::Persons->prefixed(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// DROP TABLE IF EXISTS — безопасное удаление с проверкой существования
			$wpdb->query( "DROP TABLE IF EXISTS $table;" );
		}
	}

	/**
	 * Возвращает версию миграции (semver).
	 *
	 * @return string
	 */
	public function version(): string {
		return '1.0.0';
	}
}
