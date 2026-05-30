<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\TableName;

/**
 * Class Migration_1_0_2
 *
 * Меняет тип колонки group_id в таблице enrollments с bigint на varchar(100),
 * так как ID групп являются строковыми slug'ами, а не числовыми идентификаторами.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_2 implements MigrationInterface {

	public function version(): string {
		return '1.0.2';
	}

	public function up(): void {
		global $wpdb;

		$table = TableName::Enrollments->prefixed();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );

		if ( in_array( 'group_id', $cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `group_id` varchar(100) DEFAULT NULL" );
		}
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::Enrollments->prefixed();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY COLUMN `group_id` bigint(20) unsigned DEFAULT NULL" );
	}
}