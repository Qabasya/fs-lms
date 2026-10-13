<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_83
 *
 * Журнал «Найденные ошибки»: сообщения читателей кнопки «Нашли ошибку?»
 * на страницах заданий и статей ({@see \Inc\Enums\Log\LogChannel::ContentReports}).
 *
 * @package Inc\Migrations
 *
 * На новых установках таблицу создаёт `Migration_1_0_0`; шаг идемпотентен.
 */
class Migration_1_0_83 implements MigrationInterface {

	/**
	 * DDL журнала — одна на Migration_1_0_0 и эту миграцию.
	 *
	 * @param string $charsetCollate Результат $wpdb->get_charset_collate()
	 */
	public static function contentReportDdl( string $charsetCollate ): string {
		$table = TableName::ContentReport->prefixed();

		return "CREATE TABLE $table (
			id          int unsigned        NOT NULL AUTO_INCREMENT,
			post_id     bigint(20) unsigned NOT NULL DEFAULT 0,
			task_id     bigint(20) unsigned DEFAULT NULL,
			message     text                NOT NULL,
			url         varchar(500)        DEFAULT NULL,
			user_id     bigint(20) unsigned DEFAULT NULL,
			actor_ip    varchar(45)         NOT NULL DEFAULT '',
			created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) $charsetCollate;";
	}

	public function up(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::contentReportDdl( $wpdb->get_charset_collate() ) );
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::ContentReport->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}

	public function version(): string {
		return '1.0.83';
	}
}
