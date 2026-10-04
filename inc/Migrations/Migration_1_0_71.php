<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;

/**
 * Миграция 1.0.71: Схема публичных экзаменов (15 таблиц)
 *
 * **exam_registrations.active_slot:** только `1` у действующей брони, `NULL` у отменённой/перенесённой/пропущенной.
 * Уникальный индекс `(participation_id, active_slot)` не позволяет двум действующим броням сосуществовать;
 * `NULL` в уникальном индексе MariaDB не конфликтуют.
 *
 * **exam_sessions.occupied_count:** считает подтверждённые записи учеников И действующие брони гостей (is_held=1).
 *
 * **exam_guest_applications.active_slot:** `1` пока заявка занимает место (бронь или подтверждённая запись),
 * `NULL` после истечения/отмены/неявки.
 *
 * **exam_participants.person_id:** уникален для ненулевых; уникальности по телефону нет (один телефон — два ребёнка).
 *
 * **exam_events.variant_snapshot:** JSON-объект с ключом `assessment_id`.
 *
 * **exam_access_tokens:** хеш ключа приглашения (purpose=invitation, target_id=source_id) в `exam_access_tokens`.
 *
 * Все времена в UTC (README §5).
 * Согласия гостей используют существующую таблицу `fs_lms_consents` (ConsentService::recordSelfConsent).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_71 implements MigrationInterface {

	public function version(): string {
		return '1.0.71';
	}

	public function up(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cc = $GLOBALS['wpdb']->get_charset_collate();

		foreach ( self::examDdl( $cc ) as $sql ) {
			dbDelta( $sql );
		}

		// Расширение assessment_attempts (2.2)
		$this->extendAssessmentAttempts();
	}

	public function down(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'fs_lms_exam_manual_resolutions',
			$wpdb->prefix . 'fs_lms_exam_payment_links',
			$wpdb->prefix . 'fs_lms_exam_guest_applications',
			$wpdb->prefix . 'fs_lms_exam_operation_keys',
			$wpdb->prefix . 'fs_lms_exam_events_outbox',
			$wpdb->prefix . 'fs_lms_exam_report_members',
			$wpdb->prefix . 'fs_lms_exam_reports',
			$wpdb->prefix . 'fs_lms_exam_guest_sessions',
			$wpdb->prefix . 'fs_lms_exam_access_tokens',
			$wpdb->prefix . 'fs_lms_exam_sources',
			$wpdb->prefix . 'fs_lms_exam_registrations',
			$wpdb->prefix . 'fs_lms_exam_participations',
			$wpdb->prefix . 'fs_lms_exam_participants',
			$wpdb->prefix . 'fs_lms_exam_sessions',
			$wpdb->prefix . 'fs_lms_exam_events',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS $table" );
		}

		// Попытки: убрать индекс и три колонки экзамена. `student_person_id` обратно в NOT NULL не возвращается —
		// в таблице уже могут лежать гостевые строки без ученика.
		$attempts = $wpdb->prefix . 'fs_lms_assessment_attempts';
		if ( ! empty( $wpdb->get_results( "SHOW INDEX FROM $attempts WHERE Key_name = 'exam_participation'" ) ) ) {
			$wpdb->query( "ALTER TABLE $attempts DROP INDEX exam_participation" );
		}
		foreach ( array( 'exam_participation_id', 'exam_registration_id', 'result_version' ) as $column ) {
			$wpdb->query( "ALTER TABLE $attempts DROP COLUMN IF EXISTS $column" );
		}
	}

	/**
	 * DDL для 15 таблиц экзаменов.
	 *
	 * @param string $cc Charset collate строка
	 *
	 * @return string[]
	 */
	public static function examDdl( string $cc ): array {
		$prefix = $GLOBALS['wpdb']->prefix;

		return array(
			"CREATE TABLE {$prefix}fs_lms_exam_events (
				id                         int unsigned     NOT NULL AUTO_INCREMENT,
				subject_key                varchar(50)      NOT NULL,
				title                      varchar(255)     NOT NULL,
				description                text             DEFAULT NULL,
				owner_user_id              bigint unsigned  NOT NULL,
				status                     varchar(20)      NOT NULL DEFAULT 'draft',
				period_from                date             NOT NULL,
				period_to                  date             NOT NULL,
				registration_opens_at      datetime         DEFAULT NULL,
				registration_closes_at     datetime         DEFAULT NULL,
				guest_registration_enabled tinyint(1)       NOT NULL DEFAULT 0,
				default_assessment_id      bigint unsigned  DEFAULT NULL,
				variant_snapshot           longtext         DEFAULT NULL,
				cancel_reason              text             DEFAULT NULL,
				published_at               datetime         DEFAULT NULL,
				completed_at               datetime         DEFAULT NULL,
				cancelled_at               datetime         DEFAULT NULL,
				version                    int unsigned     NOT NULL DEFAULT 1,
				created_at                 datetime         NOT NULL,
				updated_at                 datetime         NOT NULL,
				PRIMARY KEY  (id),
				KEY subject_status (subject_key, status),
				KEY owner_user_id (owner_user_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_sessions (
				id                  int unsigned      NOT NULL AUTO_INCREMENT,
				event_id            int unsigned      NOT NULL,
				assessment_id       bigint unsigned   NOT NULL,
				scheduled_at        datetime          NOT NULL,
				planned_end_at      datetime          NOT NULL,
				room_id             int unsigned      NOT NULL,
				capacity            smallint unsigned NOT NULL,
				occupied_count      smallint unsigned NOT NULL DEFAULT 0,
				responsible_user_id bigint unsigned   NOT NULL,
				status              varchar(20)       NOT NULL DEFAULT 'open',
				first_started_at    datetime          DEFAULT NULL,
				cancel_reason       text              DEFAULT NULL,
				version             int unsigned      NOT NULL DEFAULT 1,
				created_at          datetime          NOT NULL,
				updated_at          datetime          NOT NULL,
				PRIMARY KEY  (id),
				KEY event_id (event_id),
				KEY room_time (room_id, scheduled_at, planned_end_at),
				KEY scheduled_at (scheduled_at)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_participants (
				id            int unsigned     NOT NULL AUTO_INCREMENT,
				person_id     int unsigned     DEFAULT NULL,
				name_enc      text             DEFAULT NULL,
				phone_enc     text             DEFAULT NULL,
				messenger_enc text             DEFAULT NULL,
				name_hash     char(64)         DEFAULT NULL,
				phone_hash    char(64)         DEFAULT NULL,
				school_name   varchar(255)     DEFAULT NULL,
				school_key    varchar(100)     DEFAULT NULL,
				grade         tinyint unsigned DEFAULT NULL,
				anonymized_at datetime         DEFAULT NULL,
				created_at    datetime         NOT NULL,
				updated_at    datetime         NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY person_id (person_id),
				KEY name_phone (name_hash, phone_hash)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_participations (
				id                     int unsigned    NOT NULL AUTO_INCREMENT,
				event_id               int unsigned    NOT NULL,
				participant_id         int unsigned    NOT NULL,
				audience               varchar(10)     NOT NULL,
				active_registration_id int unsigned    DEFAULT NULL,
				current_attempt_id     int unsigned    DEFAULT NULL,
				source_id              int unsigned    DEFAULT NULL,
				consent_refs           longtext        DEFAULT NULL,
				transfer_allowed       tinyint(1)      NOT NULL DEFAULT 0,
				admitted_at            datetime        DEFAULT NULL,
				admitted_by_user_id    bigint unsigned DEFAULT NULL,
				version                int unsigned    NOT NULL DEFAULT 1,
				created_at             datetime        NOT NULL,
				updated_at             datetime        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_participant (event_id, participant_id),
				KEY participant_id (participant_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_registrations (
				id                 int unsigned     NOT NULL AUTO_INCREMENT,
				participation_id   int unsigned     NOT NULL,
				session_id         int unsigned     NOT NULL,
				status             varchar(20)      NOT NULL DEFAULT 'confirmed',
				active_slot        tinyint unsigned DEFAULT 1,
				request_key        varchar(64)      DEFAULT NULL,
				reason             text             DEFAULT NULL,
				actor_user_id      bigint unsigned  DEFAULT NULL,
				arrived_at         datetime         DEFAULT NULL,
				arrived_by_user_id bigint unsigned  DEFAULT NULL,
				created_at         datetime         NOT NULL,
				cancelled_at       datetime         DEFAULT NULL,
				transferred_at     datetime         DEFAULT NULL,
				missed_at          datetime         DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY participation_active (participation_id, active_slot),
				KEY session_status (session_id, status)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_sources (
				id                     int unsigned     NOT NULL AUTO_INCREMENT,
				event_id               int unsigned     NOT NULL,
				school_key             varchar(100)     DEFAULT NULL,
				school_name            varchar(255)     NOT NULL,
				school_name_normalized varchar(255)     NOT NULL,
				grade                  tinyint unsigned NOT NULL,
				teacher_name           varchar(255)     NOT NULL,
				label                  varchar(255)     NOT NULL DEFAULT '',
				is_active              tinyint(1)       NOT NULL DEFAULT 1,
				key_generation         int unsigned     NOT NULL DEFAULT 0,
				key_revoked_at         datetime         DEFAULT NULL,
				created_by_user_id     bigint unsigned  NOT NULL,
				version                int unsigned     NOT NULL DEFAULT 1,
				created_at             datetime         NOT NULL,
				updated_at             datetime         NOT NULL,
				PRIMARY KEY  (id),
				KEY event_id (event_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_access_tokens (
				id                int unsigned    NOT NULL AUTO_INCREMENT,
				purpose           varchar(20)     NOT NULL,
				target_id         int unsigned    NOT NULL,
				token_hash        char(64)        NOT NULL,
				generation        int unsigned    NOT NULL DEFAULT 1,
				expires_at        datetime        DEFAULT NULL,
				revoked_at        datetime        DEFAULT NULL,
				consumed_at       datetime        DEFAULT NULL,
				issuer_user_id    bigint unsigned NOT NULL,
				passed_at         datetime        DEFAULT NULL,
				passed_by_user_id bigint unsigned DEFAULT NULL,
				created_at        datetime        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY purpose_target (purpose, target_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_guest_sessions (
				id               int unsigned NOT NULL AUTO_INCREMENT,
				cookie_hash      char(64)     NOT NULL,
				scope            varchar(20)  NOT NULL,
				source_id        int unsigned DEFAULT NULL,
				participation_id int unsigned DEFAULT NULL,
				registration_id  int unsigned DEFAULT NULL,
				generation       int unsigned NOT NULL DEFAULT 1,
				issued_at        datetime     NOT NULL,
				expires_at       datetime     NOT NULL,
				revoked_at       datetime     DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cookie_hash (cookie_hash),
				KEY participation_id (participation_id),
				KEY source_id (source_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_reports (
				id                  int unsigned    NOT NULL AUTO_INCREMENT,
				event_id            int unsigned    NOT NULL,
				title               varchar(255)    NOT NULL,
				owner_user_id       bigint unsigned NOT NULL,
				recipient_source_id int unsigned    DEFAULT NULL,
				expires_at          datetime        NOT NULL,
				revoked_at          datetime        DEFAULT NULL,
				version             int unsigned    NOT NULL DEFAULT 1,
				created_at          datetime        NOT NULL,
				PRIMARY KEY  (id),
				KEY event_id (event_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_report_members (
				id               int unsigned NOT NULL AUTO_INCREMENT,
				report_id        int unsigned NOT NULL,
				participation_id int unsigned NOT NULL,
				consent_ref      int unsigned DEFAULT NULL,
				created_at       datetime     NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY report_participation (report_id, participation_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_events_outbox (
				id                bigint unsigned   NOT NULL AUTO_INCREMENT,
				event_uuid        char(36)          NOT NULL,
				type              varchar(50)       NOT NULL,
				aggregate_type    varchar(30)       NOT NULL,
				aggregate_id      int unsigned      NOT NULL,
				aggregate_version int unsigned      NOT NULL DEFAULT 0,
				payload           longtext          DEFAULT NULL,
				available_at      datetime          NOT NULL,
				processed_at      datetime          DEFAULT NULL,
				attempts          smallint unsigned NOT NULL DEFAULT 0,
				leased_until      datetime          DEFAULT NULL,
				last_error        text              DEFAULT NULL,
				created_at        datetime          NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_uuid (event_uuid),
				KEY pending (processed_at, available_at)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_operation_keys (
				id           bigint unsigned NOT NULL AUTO_INCREMENT,
				scope        varchar(60)     NOT NULL,
				operation    varchar(40)     NOT NULL,
				request_key  varchar(64)     NOT NULL,
				payload_hash char(64)        NOT NULL,
				result_ref   longtext        DEFAULT NULL,
				expires_at   datetime        NOT NULL,
				created_at   datetime        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_op_key (scope, operation, request_key),
				KEY expires_at (expires_at)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_guest_applications (
				id                 int unsigned     NOT NULL AUTO_INCREMENT,
				event_id           int unsigned     NOT NULL,
				session_id         int unsigned     NOT NULL,
				source_id          int unsigned     NOT NULL,
				participant_id     int unsigned     DEFAULT NULL,
				identity_hash      char(64)         NOT NULL,
				active_slot        tinyint unsigned DEFAULT 1,
				state              varchar(30)      NOT NULL DEFAULT 'hold',
				is_held            tinyint(1)       NOT NULL DEFAULT 0,
				hold_expires_at    datetime         DEFAULT NULL,
				request_key        varchar(64)      NOT NULL,
				draft_enc          longtext         DEFAULT NULL,
				source_snapshot    longtext         DEFAULT NULL,
				consent_refs       longtext         DEFAULT NULL,
				participation_id   int unsigned     DEFAULT NULL,
				registration_id    int unsigned     DEFAULT NULL,
				ip_hash            char(64)         DEFAULT NULL,
				created_by_user_id bigint unsigned  DEFAULT NULL,
				version            int unsigned     NOT NULL DEFAULT 1,
				created_at         datetime         NOT NULL,
				updated_at         datetime         NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY identity_active (event_id, identity_hash, active_slot),
				UNIQUE KEY source_request (source_id, request_key),
				KEY session_held (session_id, is_held),
				KEY hold_expiry (is_held, hold_expires_at),
				KEY ip_held (ip_hash, is_held),
				KEY source_held (source_id, is_held),
				KEY state (state)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_payment_links (
				id                 int unsigned    NOT NULL AUTO_INCREMENT,
				application_id     int unsigned    NOT NULL,
				wc_order_id        bigint unsigned NOT NULL,
				wc_order_item_id   bigint unsigned NOT NULL,
				product_id         bigint unsigned NOT NULL,
				amount             decimal(10,2)   NOT NULL DEFAULT 0.00,
				currency           varchar(3)      NOT NULL DEFAULT 'RUB',
				payment_state      varchar(20)     NOT NULL DEFAULT 'pending',
				last_reconciled_at datetime        DEFAULT NULL,
				created_at         datetime        NOT NULL,
				updated_at         datetime        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY wc_order_item_id (wc_order_item_id),
				KEY application_id (application_id),
				KEY wc_order_id (wc_order_id)
			) $cc;",

			"CREATE TABLE {$prefix}fs_lms_exam_manual_resolutions (
				id               int unsigned    NOT NULL AUTO_INCREMENT,
				application_id   int unsigned    DEFAULT NULL,
				participation_id int unsigned    DEFAULT NULL,
				kind             varchar(30)     NOT NULL,
				reason           text            NOT NULL,
				actor_user_id    bigint unsigned NOT NULL,
				amount           decimal(10,2)   DEFAULT NULL,
				old_session_id   int unsigned    DEFAULT NULL,
				new_session_id   int unsigned    DEFAULT NULL,
				created_at       datetime        NOT NULL,
				PRIMARY KEY  (id),
				KEY application_id (application_id),
				KEY participation_id (participation_id)
			) $cc;",
		);
	}

	private function extendAssessmentAttempts(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'fs_lms_assessment_attempts';
		$cc    = $wpdb->get_charset_collate();

		// Добавить колонки если их ещё нет
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM $table" );
		$existing = array_column( $columns, 'Field' );

		if ( ! in_array( 'exam_participation_id', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN exam_participation_id int unsigned DEFAULT NULL AFTER group_lesson_id" );
		}

		if ( ! in_array( 'exam_registration_id', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN exam_registration_id int unsigned DEFAULT NULL AFTER exam_participation_id" );
		}

		if ( ! in_array( 'result_version', $existing, true ) ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN result_version int unsigned NOT NULL DEFAULT 0 AFTER approved_by_user_id" );
		}

		// Модифицировать student_person_id на NULL
		$wpdb->query( "ALTER TABLE $table MODIFY student_person_id int unsigned DEFAULT NULL" );

		// Добавить уникальный индекс на exam_participation_id
		$indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'exam_participation'" );
		if ( empty( $indexes ) ) {
			$wpdb->query( "ALTER TABLE $table ADD UNIQUE KEY exam_participation (exam_participation_id)" );
		}
	}
}
