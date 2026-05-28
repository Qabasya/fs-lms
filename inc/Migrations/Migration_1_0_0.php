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
			wp_user_id bigint(20) unsigned DEFAULT NULL,                 -- ID пользователя WP
			email varchar(255) DEFAULT NULL,                             -- Email (дубликат для поиска)
			full_name_enc longblob DEFAULT NULL,                         -- ФИО (шифрованное)
			doc_number_enc longblob DEFAULT NULL,                        -- Номер документа (шифрованный)
			inn_enc longblob DEFAULT NULL,                               -- ИНН (шифрованный)
			snils_enc longblob DEFAULT NULL,                             -- СНИЛС (шифрованный)
			address_enc longblob DEFAULT NULL,                           -- Адрес (шифрованный)
			phone_enc longblob DEFAULT NULL,                             -- Телефон (шифрованный)
			doc_number_hash varchar(64) DEFAULT NULL,                    -- Хеш номера документа (для поиска)
			inn_hash varchar(64) DEFAULT NULL,                           -- Хеш ИНН (для поиска)
			deleted_at datetime DEFAULT NULL,                            -- Мягкое удаление
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
			student_person_id bigint(20) unsigned DEFAULT NULL,          -- ID ученика (из persons)
			parent_person_id bigint(20) unsigned DEFAULT NULL,           -- ID родителя (из persons)
			period_key varchar(50) NOT NULL,                             -- Учебный период
			status varchar(50) NOT NULL,                                 -- Статус заявки
			join_code_hash varchar(64) DEFAULT NULL,                     -- Хеш кода для вступления
			join_code_expires_at datetime DEFAULT NULL,                  -- Срок действия кода
			student_email_hash varchar(64) DEFAULT NULL,                 -- Хеш email ученика (для поиска)
			converted_to_enrollment_id bigint(20) unsigned DEFAULT NULL, -- ID зачисления после конвертации
			form_data longtext DEFAULT NULL,                             -- Данные формы (JSON)
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
			guardian_person_id bigint(20) unsigned NOT NULL,             -- ID опекуна
			student_person_id bigint(20) unsigned NOT NULL,              -- ID ученика
			relation_type varchar(50) NOT NULL,                          -- Тип связи (мать, отец, опекун)
			valid_from date NOT NULL,                                    -- Дата начала действия
			valid_to date DEFAULT NULL,                                  -- Дата окончания действия
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
			student_person_id bigint(20) unsigned NOT NULL,              -- ID ученика
			source_application_id bigint(20) unsigned DEFAULT NULL,      -- ID исходной заявки
			group_id bigint(20) unsigned DEFAULT NULL,                   -- ID группы
			subject_key varchar(50) NOT NULL,                            -- Ключ предмета
			period_key varchar(50) NOT NULL,                             -- Учебный период
			status varchar(50) NOT NULL,                                 -- Статус зачисления
			enrolled_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,     -- Дата зачисления
			terminated_at datetime DEFAULT NULL,                         -- Дата завершения обучения
			terminated_reason text DEFAULT NULL,                         -- Причина завершения
			terminated_by_user_id bigint(20) unsigned DEFAULT NULL,      -- Кто завершил
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
			application_id bigint(20) unsigned DEFAULT NULL,             -- ID заявки
			person_id bigint(20) unsigned DEFAULT NULL,                  -- ID человека
			subject_role varchar(20) NOT NULL,                           -- Роль субъекта (student, parent)
			consent_type varchar(50) NOT NULL,                           -- Тип согласия
			version varchar(20) NOT NULL,                                -- Версия согласия
			ip_address varchar(45) NOT NULL,                             -- IP-адрес подписавшего
			user_agent varchar(255) NOT NULL,                            -- User-Agent браузера
			accepted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,     -- Дата подписания
			valid_until datetime DEFAULT NULL,                           -- Действительно до
			withdrawn_at datetime DEFAULT NULL,                          -- Дата отзыва
			withdrawn_reason text DEFAULT NULL,                          -- Причина отзыва
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY person_id (person_id),
			KEY consent_type (consent_type)
		) $cc;"
		);

		// ===== 6. Таблица аудита действий (audit_log) =====
		$audit_log = TableName::AuditLog->prefixed();
		dbDelta(
			"CREATE TABLE $audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_user_id bigint(20) unsigned DEFAULT NULL,              -- ID пользователя (из WP)
			actor_role varchar(50) DEFAULT NULL,                         -- Роль пользователя
			action varchar(100) NOT NULL,                                -- Тип действия
			target_type varchar(50) DEFAULT NULL,                        -- Тип цели (application, enrollment)
			target_id bigint(20) unsigned DEFAULT NULL,                  -- ID цели
			details_json longtext DEFAULT NULL,                          -- Детали в JSON
			actor_ip varchar(45) NOT NULL,                               -- IP-адрес
			actor_ua text DEFAULT NULL,                                  -- User-Agent
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
			actor_user_id bigint(20) unsigned NOT NULL,                  -- ID пользователя (из WP)
			actor_role varchar(50) DEFAULT NULL,                         -- Роль пользователя
			person_id bigint(20) unsigned NOT NULL,                      -- ID человека (из persons)
			fields_accessed text NOT NULL,                               -- Какие поля были запрошены
			access_reason varchar(255) NOT NULL,                         -- Причина доступа
			actor_ip varchar(45) NOT NULL,                               -- IP-адрес
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
