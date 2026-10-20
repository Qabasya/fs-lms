<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_94
 *
 * Страховочная доводка колонок, добавленных миграциями 1.0.86–1.0.93.
 *
 * Те миграции писали `ADD COLUMN IF NOT EXISTS` — синтаксис только MariaDB. На MySQL (и части хостингов) запрос
 * падал с синтаксической ошибкой тихо, а версия схемы всё равно считалась применённой: колонка не создавалась, и любое
 * сохранение сеанса или ссылки школы с этим полем падало без текста. Здесь каждая колонка проверяется через
 * `SHOW COLUMNS` и добавляется обычным `ADD COLUMN` — работает и на MariaDB, и на MySQL. Затем переносит бесплатные ссылки
 * (`is_free = 1`) в скидку 100 %. Идемпотентна.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_94 implements MigrationInterface {

	/**
	 * Колонки, которые обязаны быть: таблица (кейс TableName) → колонка → определение с позицией.
	 *
	 * @return list<array{table: TableName, column: string, definition: string}>
	 */
	public static function columns(): array {
		return array(
			array( 'table' => TableName::ExamGuestSessions, 'column' => 'report_id', 'definition' => 'int unsigned DEFAULT NULL AFTER `registration_id`' ),
			array( 'table' => TableName::ExamSources, 'column' => 'is_free', 'definition' => 'tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`' ),
			array( 'table' => TableName::ExamSources, 'column' => 'discount_percent', 'definition' => 'tinyint unsigned NOT NULL DEFAULT 0 AFTER `is_free`' ),
			array( 'table' => TableName::ExamSources, 'column' => 'session_ids', 'definition' => 'text DEFAULT NULL AFTER `discount_percent`' ),
			array( 'table' => TableName::AssessmentAnswers, 'column' => 'first_answered_at', 'definition' => 'datetime DEFAULT NULL' ),
			array( 'table' => TableName::AssessmentAnswers, 'column' => 'answered_at', 'definition' => 'datetime DEFAULT NULL' ),
			array( 'table' => TableName::TaskAttempts, 'column' => 'first_answer_sec', 'definition' => 'int unsigned DEFAULT NULL' ),
			array( 'table' => TableName::TaskAttempts, 'column' => 'last_answer_sec', 'definition' => 'int unsigned DEFAULT NULL' ),
			array( 'table' => TableName::ExamSessions, 'column' => 'visible_to_students', 'definition' => 'tinyint(1) NOT NULL DEFAULT 1 AFTER `status`' ),
		);
	}

	/** Запрос добавления колонки без `IF NOT EXISTS`. */
	public static function addColumnSql( string $table, string $column, string $definition ): string {
		return "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
	}

	public function up(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		foreach ( self::columns() as $spec ) {
			$table = $spec['table']->prefixed();
			if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $spec['column'] ) ) ) {
				continue;
			}
			$wpdb->query( self::addColumnSql( $table, $spec['column'], $spec['definition'] ) );
		}

		// Бесплатные ссылки до 1.0.88 (`is_free = 1`) — скидка 100 %. Если `discount_percent` тогда не создалась, перенос не выполнился.
		$sources = TableName::ExamSources->prefixed();
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$sources` LIKE %s", 'is_free' ) ) ) {
			$wpdb->query( "UPDATE `$sources` SET `discount_percent` = 100 WHERE `is_free` = 1 AND `discount_percent` = 0" );
		}
		// phpcs:enable
	}

	public function down(): void {
		// Колонки безвредны: откат не нужен.
	}

	public function version(): string {
		return '1.0.94';
	}
}
