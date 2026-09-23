<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_33
 *
 * Журнал ошибок пользователей + счётчик сдач работы.
 *
 * @package Inc\Migrations
 *
 * - `error_log` — ошибки, которые видят пользователи: код, номер инцидента, кто, где,
 *   IP/UA (журнал «Ошибки», {@see \Inc\Enums\Log\LogChannel::Errors}).
 * - `submissions.attempt_count` — сколько раз ученик сдавал работу (осмысленно на
 *   агрегатной строке, task_id IS NULL). Раньше число сдач выводилось из истории
 *   попыток по заданиям, но при пересдаче теперь перепроверяются только незачтённые
 *   задания, и по истории сдачи больше не посчитать.
 *
 * На новых установках всё создаёт `Migration_1_0_0`; шаги идемпотентны.
 */
class Migration_1_0_33 implements MigrationInterface {

	/**
	 * DDL журнала ошибок — одна на Migration_1_0_0 и эту миграцию.
	 *
	 * @param string $charsetCollate Результат $wpdb->get_charset_collate()
	 */
	public static function errorLogDdl( string $charsetCollate ): string {
		$table = TableName::ErrorLog->prefixed();

		return "CREATE TABLE $table (
			id          int unsigned        NOT NULL AUTO_INCREMENT,
			ref         char(6)             NOT NULL,
			code        varchar(40)         NOT NULL,
			message     varchar(500)        NOT NULL DEFAULT '',
			source      varchar(10)         NOT NULL DEFAULT 'server',
			user_id     bigint(20) unsigned DEFAULT NULL,
			action      varchar(100)        DEFAULT NULL,
			url         varchar(500)        DEFAULT NULL,
			context     text                DEFAULT NULL,
			actor_ip    varchar(45)         NOT NULL DEFAULT '',
			actor_ua    text                DEFAULT NULL,
			created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY ref (ref),
			KEY code (code),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charsetCollate;";
	}

	public function up(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::errorLogDdl( $wpdb->get_charset_collate() ) );

		$submissions = TableName::Submissions->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$hasColumn = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$submissions` LIKE %s", 'attempt_count' ) );
		if ( ! $hasColumn ) {
			$wpdb->query( "ALTER TABLE `$submissions` ADD COLUMN `attempt_count` smallint unsigned NOT NULL DEFAULT 0 AFTER `graded_at`" );
		}
		// phpcs:enable
	}

	public function down(): void {
		global $wpdb;

		$errorLog    = TableName::ErrorLog->prefixed();
		$submissions = TableName::Submissions->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `$errorLog`" );
		$wpdb->query( "ALTER TABLE `$submissions` DROP COLUMN IF EXISTS `attempt_count`" );
		// phpcs:enable
	}

	public function version(): string {
		return '1.0.33';
	}
}
