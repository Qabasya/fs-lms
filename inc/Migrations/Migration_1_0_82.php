<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_82
 *
 * Ссылка на трансляцию занятий группы: `groups.broadcast_url`.
 *
 * @package Inc\Migrations
 *
 * Раньше ссылка жила в шаге курса «Трансляция», а курс делят несколько групп, и у
 * каждой своя комната. Теперь адрес один на группу, шаг трансляции плеер добавляет
 * сам. На новых установках колонку создаёт `Migration_1_0_0`; шаг идемпотентен.
 */
class Migration_1_0_82 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::Groups->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hasColumn = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'broadcast_url' ) );
		if ( ! $hasColumn ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `broadcast_url` varchar(1000) DEFAULT NULL AFTER `room_id`" );
		}
		// phpcs:enable
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::Groups->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `broadcast_url`" );
	}

	public function version(): string {
		return '1.0.82';
	}
}
