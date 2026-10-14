<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_88
 *
 * Скидка по ссылке преподавателя: `exam_sources.discount_percent` (0–100) заменяет флаг `is_free`. 100 — запись без оплаты
 * (прежняя бесплатная ссылка), 1–99 — скидка на цену товара экзамена. Бесплатные ссылки переносятся в 100. Идемпотентна:
 * на новых установках колонку создаёт `Migration_1_0_84::examDdl()`; `is_free` остаётся в таблице, но кодом не читается.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_88 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamSources->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `discount_percent` tinyint unsigned NOT NULL DEFAULT 0 AFTER `is_free`" );
		$wpdb->query( "UPDATE `$table` SET `discount_percent` = 100 WHERE `is_free` = 1 AND `discount_percent` = 0" );
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.88';
	}
}
