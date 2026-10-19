<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_92
 *
 * Ограничение сеансов по ссылке школы: `exam_sources.session_ids` — JSON-список ID сеансов проведения, на которые можно
 * записаться по этой ссылке. `NULL` — ограничения нет (все сеансы), как у всех прежних ссылок. Идемпотентна: на новых
 * установках колонку создаёт `Migration_1_0_84::examDdl()`.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_92 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamSources->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `session_ids` text DEFAULT NULL AFTER `discount_percent`" );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.92';
	}
}
