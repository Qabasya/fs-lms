<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_93
 *
 * Показ сеанса ученикам: `exam_sessions.visible_to_students` — 1 (по умолчанию) сеанс виден ученикам и родителям и они могут
 * на него записываться, 0 — на сеанс записываются только гости (запись гостей по ссылкам школ должна быть включена).
 * Существующие сеансы остаются видимыми. Идемпотентна: на новых установках колонку создаёт `Migration_1_0_84::examDdl()`.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_93 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamSessions->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `visible_to_students` tinyint(1) NOT NULL DEFAULT 1 AFTER `status`" );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.93';
	}
}
