<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_1_0
 *
 * notifications — in-app уведомления кабинета.
 *
 * Отдельная версионная миграция, а не DDL внутри `Migration_1_0_0::up()`:
 * `MigrationRunner::run()` накатывает `up()` только у миграций с `version()` выше
 * уже применённой (`fs_lms_schema_version`). Установки, получившие `1.0.0` до
 * появления этой таблицы, никогда не увидели бы её, если бы DDL просто дописали
 * в тело уже применённой `Migration_1_0_0` — нужна НОВАЯ версия, которую раннер
 * применит поверх накатанной.
 *
 * @package Inc\Migrations
 */
class Migration_1_1_0 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$cc            = $wpdb->get_charset_collate();
		$notifications = TableName::Notifications->prefixed();

		dbDelta(
			"CREATE TABLE $notifications (
			id                 bigint unsigned NOT NULL AUTO_INCREMENT,
			recipient_user_id  bigint unsigned NOT NULL,
			type               varchar(40)  NOT NULL,
			group_id           smallint unsigned DEFAULT NULL,
			entity_type        varchar(30)  DEFAULT NULL,
			entity_id          bigint unsigned DEFAULT NULL,
			payload            longtext     DEFAULT NULL,
			url                varchar(500) DEFAULT NULL,
			dedupe_key         varchar(120) NOT NULL,
			created_at         datetime     NOT NULL,
			seen_at            datetime     DEFAULT NULL,
			read_at            datetime     DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY recipient_dedupe (recipient_user_id, dedupe_key),
			KEY recipient_created (recipient_user_id, created_at),
			KEY recipient_seen (recipient_user_id, seen_at)
		) $cc;"
		);
	}

	/**
	 * Полный снос таблицы уже покрыт {@see Migration_1_0_0::down()} (список всех
	 * таблиц плагина для деинсталляции) — здесь дублируем на случай отката только
	 * этой версии; DROP TABLE IF EXISTS идемпотентен.
	 */
	public function down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . TableName::Notifications->prefixed() );
	}

	public function version(): string {
		return '1.1.0';
	}
}
