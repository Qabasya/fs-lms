<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\TableName;

/**
 * Class Migration_1_0_1
 *
 * Добавляет колонку join_code_enc в таблицу applications для хранения
 * зашифрованного JOIN-кода, необходимого для отображения ссылки в админ-панели.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_1 implements MigrationInterface {

	public function version(): string {
		return '1.0.1';
	}

	public function up(): void {
		global $wpdb;

		$table = TableName::Applications->prefixed();

		// Проверяем, нет ли уже колонки (idempotent)
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore

		if ( ! in_array( 'join_code_enc', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `join_code_enc` BLOB NULL AFTER `join_code_hash`" ); // phpcs:ignore
		}
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::Applications->prefixed();
		$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN IF EXISTS `join_code_enc`" ); // phpcs:ignore
	}
}