<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_97
 *
 * Колонка `group_lessons.broadcast_url` — своя ссылка на трансляцию занятия. Пусто — действует ссылка группы
 * (`groups.broadcast_url`); заполнена — преподаватель сменил её для этого занятия (дополнительное занятие).
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` и обычный `ADD COLUMN` (образец — {@see Migration_1_0_94}).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_97 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::GroupLessons->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'broadcast_url' ) ) ) {
			return;
		}
		$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'broadcast_url', 'varchar(1000) DEFAULT NULL AFTER `extra_key`' ) );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.97';
	}
}
