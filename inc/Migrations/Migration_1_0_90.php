<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_90
 *
 * Время ответов на задания: у экзаменов (`assessment_answers`) — момент первого и последнего
 * изменения ответа, у обычных работ (`task_attempts`) — секунды от начала раунда до первого
 * и до последнего ответа. Старые строки остаются без значений.
 *
 * @package Inc\Migrations
 *
 * На новых установках колонки создаёт `Migration_1_0_0`; шаг идемпотентен.
 */
class Migration_1_0_90 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$answers  = TableName::AssessmentAnswers->prefixed();
		$attempts = TableName::TaskAttempts->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$answers` ADD COLUMN IF NOT EXISTS `first_answered_at` datetime DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE `$answers` ADD COLUMN IF NOT EXISTS `answered_at` datetime DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE `$attempts` ADD COLUMN IF NOT EXISTS `first_answer_sec` int unsigned DEFAULT NULL" );
		$wpdb->query( "ALTER TABLE `$attempts` ADD COLUMN IF NOT EXISTS `last_answer_sec` int unsigned DEFAULT NULL" );
		// phpcs:enable
	}

	public function down(): void {
		// Колонки безвредны: откат не нужен.
	}

	public function version(): string {
		return '1.0.90';
	}
}
