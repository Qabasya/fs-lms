<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\DTO\Enrollment\StudentDataDTO;
use Inc\Enums\Settings\TableName;
use Inc\Services\Security\PiiCryptoService;
use Inc\Shared\PluginLogger;

/**
 * Class Migration_1_0_8
 *
 * Хэш логина в таблице заявок: уникальность логина среди незачисленных заявок.
 *
 * @package Inc\Migrations
 *
 * ### Зачем отдельная миграция
 *
 * Логин незачисленной заявки лежит только в зашифрованном `student_data_enc`, и две заявки
 * с одинаковым логином проходили — вторая падала при зачислении на `wp_insert_user`. Колонка
 * `username_hash` (как `student_email_hash`) даёт искать логин без расшифровки.
 *
 * На новых установках колонку создаёт `Migration_1_0_0`; здесь — для установок, где схема 1.0.0
 * уже применена: `MigrationRunner` в `Init::run()` накатывает версию выше сохранённой.
 * Шаги идемпотентны: колонка добавляется, только если её нет, хэш заполняется там, где пусто.
 */
class Migration_1_0_8 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$table = TableName::Applications->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hasColumn = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'username_hash' ) );

		if ( ! $hasColumn ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `username_hash` char(64) DEFAULT NULL AFTER `student_email_hash`, ADD KEY `username_hash` (`username_hash`)" );
		}

		$this->backfill( $table );
	}

	public function down(): void {
		global $wpdb;

		$table = TableName::Applications->prefixed();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `username_hash`" );
	}

	public function version(): string {
		return '1.0.8';
	}

	/**
	 * Заполняет хэш логина у существующих заявок.
	 *
	 * Без ключа шифрования расшифровать заявки нечем — пропускаем с предупреждением: заявки
	 * без хэша просто не участвуют в проверке, пока логин не сохранят заново.
	 *
	 * @param string $table Таблица заявок
	 */
	private function backfill( string $table ): void {
		global $wpdb;

		try {
			$crypto = new PiiCryptoService();
		} catch ( \Throwable $e ) {
			PluginLogger::warning( 'Migration_1_0_8', 'Хэш логинов заявок не заполнен: нет ключа шифрования', array( 'error' => $e->getMessage() ) );
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, student_data_enc FROM `$table` WHERE username_hash IS NULL AND student_data_enc IS NOT NULL", ARRAY_A );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			try {
				$username = StudentDataDTO::fromArray( json_decode( $crypto->decrypt( (string) $row['student_data_enc'] ), true ) ?? array() )->username;
			} catch ( \Throwable ) {
				continue;
			}

			if ( '' !== $username ) {
				$wpdb->update( $table, array( 'username_hash' => $crypto->hash( $username ) ), array( 'id' => (int) $row['id'] ) );
			}
		}
	}
}
