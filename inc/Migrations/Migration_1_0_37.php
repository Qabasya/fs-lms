<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_37
 *
 * Время работы ученика над работой-шагом.
 *
 * @package Inc\Migrations
 *
 * - `submissions.duration_sec` — сколько секунд прошло от открытия работы до
 *   последней сдачи (агрегатная строка, task_id IS NULL).
 * - `task_attempts.duration_sec` — то же для каждого раунда сдачи: строка submissions
 *   при пересдаче перезаписывается, история раундов живёт в task_attempts.
 * - `task_attempts.answered_at` — когда ученик последний раз менял ответ на задание.
 *
 * Длительность, а не момент открытия: `task_attempts.created_at` ставит СУБД в своём
 * часовом поясе, а время сдачи — WP (ClockInterface), разность моментов из разных
 * источников была бы сдвинута на смещение пояса.
 *
 * Старые сдачи остаются с NULL — в интерфейсе у них «—». На новых установках
 * колонки создаёт `Migration_1_0_0`; шаги идемпотентны.
 */
class Migration_1_0_37 implements MigrationInterface {

	public function up(): void {
		$this->addColumn( TableName::Submissions->prefixed(), 'duration_sec', 'int unsigned DEFAULT NULL AFTER `attempt_count`' );
		$this->addColumn( TableName::TaskAttempts->prefixed(), 'duration_sec', 'int unsigned DEFAULT NULL AFTER `item_feedback`' );
		$this->addColumn( TableName::TaskAttempts->prefixed(), 'answered_at', 'datetime DEFAULT NULL AFTER `duration_sec`' );
	}

	public function down(): void {
		global $wpdb;

		$submissions  = TableName::Submissions->prefixed();
		$taskAttempts = TableName::TaskAttempts->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$submissions` DROP COLUMN IF EXISTS `duration_sec`" );
		$wpdb->query( "ALTER TABLE `$taskAttempts` DROP COLUMN IF EXISTS `duration_sec`" );
		$wpdb->query( "ALTER TABLE `$taskAttempts` DROP COLUMN IF EXISTS `answered_at`" );
		// phpcs:enable
	}

	public function version(): string {
		return '1.0.37';
	}

	private function addColumn( string $table, string $column, string $definition ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$hasColumn = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
		if ( ! $hasColumn ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition" );
		}
		// phpcs:enable
	}
}
