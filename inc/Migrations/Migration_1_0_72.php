<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_72
 *
 * `consents.version` хранит SHA-256 текста согласия (64 знака), а колонка была `varchar(20)`: в строгом режиме MariaDB вставка согласия
 * отвергалась, и `ConsentService` молча возвращал 0 — согласия не записывались вовсе (обнаружено при проверке гостевой формы экзаменов).
 * Колонка расширяется до 64. Идемпотентна; на новых установках её создаёт `Migration_1_0_0`.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_72 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::Consents->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` MODIFY COLUMN `version` varchar(64) NOT NULL" );
		// phpcs:enable
	}

	public function down(): void {
		// Сужать обратно нельзя: хеши не поместятся.
	}

	public function version(): string {
		return '1.0.72';
	}
}
