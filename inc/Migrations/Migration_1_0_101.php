<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_101
 *
 * Колонка `groups.is_shared` — «общая (дополнительная)» группа: курс и расписание поверх основной группы ученика.
 * Запись в неё — не зачисление: без договора и приказа, не считается «группой ученика» (экзамены, карточка),
 * не удерживает ученика активным после отчисления из основной группы.
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` и обычный `ADD COLUMN` (образец — {@see Migration_1_0_94}).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_101 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::Groups->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'is_shared' ) ) ) {
			return;
		}
		$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'is_shared', 'tinyint(1) NOT NULL DEFAULT 0 AFTER `access_mode`' ) );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.101';
	}
}
