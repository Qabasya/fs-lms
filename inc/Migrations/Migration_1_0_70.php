<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_70
 *
 * Таблица проверок ответа кнопкой «Проверить ответ» внутри работы.
 *
 * @package Inc\Migrations
 *
 * Проверки идут ДО сдачи работы, поэтому в `task_attempts` им не место: оттуда
 * считается лимит сдач работы и история пересдач. Здесь копится, сколько раз ученик
 * проверял задачу в текущем раунде и ошибался ли — по этому строится жёлтая отметка
 * «верно с исправлением». На новых установках таблицу создаёт `Migration_1_0_0`;
 * `dbDelta` идемпотентен.
 */
class Migration_1_0_70 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = TableName::WorkTaskChecks->prefixed();
		$cc    = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE $table (
			id                bigint unsigned  NOT NULL AUTO_INCREMENT,
			student_person_id int unsigned     NOT NULL,
			group_lesson_id   int unsigned     NOT NULL,
			work_id           bigint unsigned  NOT NULL,
			task_id           bigint unsigned  NOT NULL,
			round             smallint unsigned NOT NULL DEFAULT 1,
			answer            json             DEFAULT NULL,
			is_correct        tinyint(1)       NOT NULL DEFAULT 0,
			created_at        datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY student_work (student_person_id, group_lesson_id, work_id),
			KEY group_lesson_id (group_lesson_id)
		) $cc;"
		);
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::WorkTaskChecks->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}

	public function version(): string {
		return '1.0.70';
	}
}
