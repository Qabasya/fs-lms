<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_91
 *
 * Режим группы «индивидуальная» (`groups.access_mode = 'individual'`): один ученик,
 * расписание ставит преподаватель в КТП на любые дни и время, автораспределения нет.
 * Значение добавляется в enum колонки; существующие строки не меняются.
 *
 * @package Inc\Migrations
 *
 * На новых установках колонку создаёт `Migration_1_0_0`; шаг идемпотентен.
 */
class Migration_1_0_91 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$groups = TableName::Groups->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$groups` MODIFY COLUMN `access_mode` enum('scheduled','open','individual') NOT NULL DEFAULT 'scheduled'" );
		// phpcs:enable
	}

	public function down(): void {
		// Расширение enum безвредно: откат не нужен (строки со значением 'individual' при сужении потерялись бы).
	}

	public function version(): string {
		return '1.0.91';
	}
}
