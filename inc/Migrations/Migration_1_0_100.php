<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_100
 *
 * Ручное подтверждение записи гостя: `exam_participations.staff_confirmed_at` / `staff_confirmed_by_user_id` — отметка
 * сотрудника «Запись подтверждена» (зелёный бейдж «Подтверждено» в строке гостя на доске проведения). Не связана с допуском
 * (`admitted_at` ставится выдачей ссылки входа) и со статусом записи `confirmed`.
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` и обычный `ADD COLUMN` (образец — {@see Migration_1_0_94}).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_100 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamParticipations->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'staff_confirmed_at' ) ) ) {
			$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'staff_confirmed_at', 'datetime DEFAULT NULL AFTER `admitted_by_user_id`' ) );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'staff_confirmed_by_user_id' ) ) ) {
			$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'staff_confirmed_by_user_id', 'bigint unsigned DEFAULT NULL AFTER `staff_confirmed_at`' ) );
		}
		// phpcs:enable
	}

	public function down(): void {
		// Колонки безвредны: откат не нужен.
	}

	public function version(): string {
		return '1.0.100';
	}
}
