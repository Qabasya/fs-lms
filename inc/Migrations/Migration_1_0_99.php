<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_99
 *
 * Снимки массовой правки заданий (Предметы → Менеджер заданий): состояние затронутых заданий
 * до правки и после неё. По снимку правка откатывается и скачивается в CSV
 * (старый номер → новые данные). `items` — JSON списка заданий партии.
 *
 * Переносимый SQL: `dbDelta()` идемпотентен и работает на MariaDB и на MySQL.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_99 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = TableName::TaskBulkSnapshots->prefixed();

		dbDelta(
			"CREATE TABLE $table (
			id               int unsigned        NOT NULL AUTO_INCREMENT,
			subject_key      varchar(64)         NOT NULL,
			status           varchar(12)         NOT NULL DEFAULT 'applying',
			actor_user_id    bigint(20) unsigned DEFAULT NULL,
			items_count      int unsigned        NOT NULL DEFAULT 0,
			items            longtext            NOT NULL,
			created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			rolled_back_at   datetime            DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY subject_key (subject_key, id)
		) {$wpdb->get_charset_collate()};"
		);
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::TaskBulkSnapshots->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}

	public function version(): string {
		return '1.0.99';
	}
}
