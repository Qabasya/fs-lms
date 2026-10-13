<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_74
 *
 * Бесплатная ссылка преподавателя: `exam_sources.is_free` — гость по такой ссылке записывается без оплаты (бронь сразу
 * превращается в запись, магазин не вызывается). Идемпотентна: на новых установках колонку создаёт
 * `Migration_1_0_71::examDdl()`.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_74 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamSources->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `is_free` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`" );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.74';
	}
}
