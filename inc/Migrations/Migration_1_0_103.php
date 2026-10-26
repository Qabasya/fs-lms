<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_103
 *
 * `applications.exam_participant_id` — гость экзамена, по личной ссылке которого подана заявка («Гости экзамена» → «Выдать ссылку»).
 * При зачислении его результаты переводятся к новому ученику ({@see \Inc\Services\Exam\ExamGuestPromotionService}).
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` и обычный `ADD COLUMN` (образец — {@see Migration_1_0_94}).
 *
 * @package Inc\Migrations
 */
class Migration_1_0_103 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::Applications->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'exam_participant_id' ) ) ) {
			$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'exam_participant_id', 'int unsigned DEFAULT NULL' ) );
		}
		// phpcs:enable
	}

	public function down(): void {
		// Колонка безвредна: откат не нужен.
	}

	public function version(): string {
		return '1.0.103';
	}
}
