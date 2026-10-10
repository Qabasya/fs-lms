<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_73
 *
 * Школьные отчёты (этап 12.3): `exam_guest_sessions.report_id` — отчёт, который открыла сессия `scope = report`.
 * Чужие колонки (`source_id`, `participation_id`) под ID отчёта не используются. Идемпотентна: на новых установках колонку создаёт
 * `Migration_1_0_71::examDdl()`.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_73 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamGuestSessions->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `report_id` int unsigned DEFAULT NULL AFTER `registration_id`" );
		if ( empty( $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'report_id'" ) ) ) {
			$wpdb->query( "ALTER TABLE `$table` ADD KEY `report_id` (`report_id`)" );
		}
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.73';
	}
}
