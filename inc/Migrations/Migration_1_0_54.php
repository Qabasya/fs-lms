<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_54
 *
 * Диагностика неудачных входов в журнале аутентификации.
 *
 * @package Inc\Migrations
 *
 * - `auth_log.reason`  — причина отказа ({@see \Inc\Enums\Auth\LoginFailReason}).
 * - `auth_log.details` — JSON с тем, что ввёл пользователь (логин как набран,
 *   длина пароля, признаки раскладки/регистра, форма, наличие токена капчи).
 *   Сам пароль не хранится.
 *
 * Старые записи остаются с NULL. На новых установках колонки создаёт
 * `Migration_1_0_0`; шаги идемпотентны.
 */
class Migration_1_0_54 implements MigrationInterface {

	public function up(): void {
		$this->addColumn( TableName::AuthLog->prefixed(), 'reason', 'varchar(50) DEFAULT NULL AFTER `result`' );
		$this->addColumn( TableName::AuthLog->prefixed(), 'details', 'text DEFAULT NULL AFTER `reason`' );
	}

	public function down(): void {
		global $wpdb;

		$authLog = TableName::AuthLog->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$authLog` DROP COLUMN IF EXISTS `details`" );
		$wpdb->query( "ALTER TABLE `$authLog` DROP COLUMN IF EXISTS `reason`" );
		// phpcs:enable
	}

	public function version(): string {
		return '1.0.54';
	}

	private function addColumn( string $table, string $column, string $definition ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$hasColumn = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
		if ( ! $hasColumn ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$column` $definition" );
		}
		// phpcs:enable
	}
}
