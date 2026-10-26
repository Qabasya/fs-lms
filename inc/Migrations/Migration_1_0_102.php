<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_102
 *
 * Ручная отметка «Оповещение отправлено» у гостя экзамена: `exam_participations.staff_notified_at` /
 * `staff_notified_by_user_id`. Предшествует отметке «Запись подтверждена» (`staff_confirmed_at`, {@see Migration_1_0_100}):
 * в списке «Гости экзамена» подтвердить запись можно только после оповещения.
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` и обычный `ADD COLUMN` (образец — {@see Migration_1_0_94}).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_102 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamParticipations->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'staff_notified_at' ) ) ) {
			$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'staff_notified_at', 'datetime DEFAULT NULL AFTER `staff_confirmed_by_user_id`' ) );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'staff_notified_by_user_id' ) ) ) {
			$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'staff_notified_by_user_id', 'bigint unsigned DEFAULT NULL AFTER `staff_notified_at`' ) );
		}
		// phpcs:enable
	}

	public function down(): void {
		// Колонки безвредны: откат не нужен.
	}

	public function version(): string {
		return '1.0.102';
	}
}
