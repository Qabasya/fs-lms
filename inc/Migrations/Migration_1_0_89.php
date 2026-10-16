<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_89
 *
 * Журнал «Экзамены»: действия с проведениями, сеансами, заявками и попытками
 * ({@see \Inc\Enums\Log\LogChannel::Exams}). Одна таблица на все события:
 * действие, актор, объект (проведение / сеанс / заявка / попытка), результат
 * и произвольный контекст в JSON.
 *
 * @package Inc\Migrations
 *
 * На новых установках таблицу создаёт `Migration_1_0_0`; шаг идемпотентен.
 */
class Migration_1_0_89 implements MigrationInterface {

	/**
	 * DDL журнала — одна на Migration_1_0_0 и эту миграцию.
	 *
	 * @param string $charsetCollate Результат $wpdb->get_charset_collate()
	 */
	public static function examLogDdl( string $charsetCollate ): string {
		$table = TableName::ExamLog->prefixed();

		return "CREATE TABLE $table (
			id               int unsigned        NOT NULL AUTO_INCREMENT,
			action           varchar(40)         NOT NULL,
			result           varchar(10)         NOT NULL DEFAULT 'success',
			actor_type       varchar(10)         NOT NULL DEFAULT 'system',
			actor_user_id    bigint(20) unsigned DEFAULT NULL,
			actor_label      varchar(255)        DEFAULT NULL,
			event_id         bigint(20) unsigned DEFAULT NULL,
			session_id       bigint(20) unsigned DEFAULT NULL,
			registration_id  bigint(20) unsigned DEFAULT NULL,
			attempt_id       bigint(20) unsigned DEFAULT NULL,
			subject_label    varchar(255)        DEFAULT NULL,
			actor_ip         varchar(45)         NOT NULL DEFAULT '',
			context          longtext            DEFAULT NULL,
			created_at       datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY event_id (event_id),
			KEY session_id (session_id),
			KEY registration_id (registration_id),
			KEY attempt_id (attempt_id),
			KEY created_at (created_at)
		) $charsetCollate;";
	}

	public function up(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::examLogDdl( $wpdb->get_charset_collate() ) );
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::ExamLog->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}

	public function version(): string {
		return '1.0.89';
	}
}
