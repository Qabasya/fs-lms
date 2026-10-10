<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_81
 *
 * Таблица заявок с лид-форм сайта (`fs_lms_leads`): тема передаёт каждую заявку
 * хуком `fs_lms_theme_lead_submitted`, плагин хранит принятые и отклонённые —
 * по отклонённым видно паттерн ботов и ложные срабатывания.
 *
 * @package Inc\Migrations
 *
 * Имя и телефон хранятся зашифрованными (`PiiCryptoService`). На новых установках
 * таблицу создаёт `Migration_1_0_0`; `dbDelta` идемпотентен.
 */
class Migration_1_0_81 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = TableName::Leads->prefixed();
		$cc    = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE $table (
			id                bigint unsigned NOT NULL AUTO_INCREMENT,
			verdict           varchar(10)  NOT NULL,
			reason            varchar(30)  NOT NULL DEFAULT '',
			name_enc          blob         NOT NULL,
			phone_enc         blob         NOT NULL,
			form_id           varchar(30)  NOT NULL DEFAULT '',
			page_url          varchar(500) NOT NULL DEFAULT '',
			ip                varchar(45)  NOT NULL DEFAULT '',
			subnet            varchar(50)  NOT NULL DEFAULT '',
			user_agent        varchar(500) NOT NULL DEFAULT '',
			fill_seconds      int unsigned NOT NULL DEFAULT 0,
			captcha           varchar(12)  NOT NULL DEFAULT '',
			captcha_challenge tinyint(1)   NOT NULL DEFAULT 0,
			is_mobile         tinyint(1)   NOT NULL DEFAULT 0,
			mail_sent         tinyint(1)   DEFAULT NULL,
			received_at       datetime     NOT NULL,
			PRIMARY KEY (id),
			KEY verdict_received (verdict, received_at),
			KEY subnet (subnet)
		) $cc;"
		);
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::Leads->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}

	public function version(): string {
		return '1.0.81';
	}
}
