<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_95
 *
 * Колонка `group_lessons.recording_skipped` — преподаватель отменил шаг «Трансляция» занятия
 * (например, в один день проведено две темы, а запись одна): записи у занятия не будет,
 * шаг у ученика скрыт, «занятие без записи» не напоминает.
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` и обычный `ADD COLUMN`
 * (образец — {@see Migration_1_0_94}).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_95 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::GroupLessons->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'recording_skipped' ) ) ) {
			return;
		}
		$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'recording_skipped', 'tinyint(1) NOT NULL DEFAULT 0 AFTER `recording_link`' ) );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.95';
	}
}
