<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_62
 *
 * Внешняя ссылка на запись занятия — отдельно от указателя хранилища.
 *
 * @package Inc\Migrations
 *
 * `group_lessons.recording_url` держит указатель `s3://{bucket}/{key}` (авто-привязка
 * модуля «Видеозаписи занятий»), `group_lessons.recording_link` — ссылку на запись в
 * облаке, которую вставил преподаватель. Раньше обе жили в одном поле, и запасной
 * ссылке на случай недоступного хранилища было негде храниться.
 *
 * Прямые `http(s)://` из `recording_url` (ручные правки из КТП) переезжают в
 * `recording_link`. На новых установках колонку создаёт `Migration_1_0_0`;
 * шаги идемпотентны.
 */
class Migration_1_0_62 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::GroupLessons->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hasColumn = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'recording_link' ) );
		if ( ! $hasColumn ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `recording_link` varchar(1000) DEFAULT NULL AFTER `recording_url`" );
		}

		$wpdb->query(
			"UPDATE `$table`
			 SET recording_link = recording_url, recording_url = NULL
			 WHERE recording_url LIKE 'http%' AND ( recording_link IS NULL OR recording_link = '' )"
		);
		// phpcs:enable
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::GroupLessons->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `recording_link`" );
		// phpcs:enable
	}

	public function version(): string {
		return '1.0.62';
	}
}
