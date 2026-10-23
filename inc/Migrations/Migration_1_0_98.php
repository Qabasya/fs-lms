<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_98
 *
 * Значение `survey` («Опрос») в enum колонки `submissions.work_type`. Без него сдача работы-опроса не
 * запишется: MySQL в строгом режиме отклонит значение, не входящее в enum.
 *
 * Переносимый SQL: `MODIFY COLUMN` с полным списком значений работает и на MariaDB, и на MySQL.
 * Идемпотентна: если `survey` в типе колонки уже есть, ничего не делает.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_98 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::Submissions->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'work_type' ), ARRAY_A );
		if ( ! is_array( $column ) || str_contains( (string) ( $column['Type'] ?? '' ), "'survey'" ) ) {
			return;
		}
		$wpdb->query( "ALTER TABLE `$table` MODIFY COLUMN `work_type` enum('practice','independent','homework','survey') NOT NULL" );
		// phpcs:enable
	}

	public function down(): void {
		// Лишнее значение enum безвредно: откат не нужен.
	}

	public function version(): string {
		return '1.0.98';
	}
}
