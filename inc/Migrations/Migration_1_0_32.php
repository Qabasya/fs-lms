<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Enrollment\ApplicationStatus;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_32
 *
 * Срок заявки отдельно от срока JOIN-ссылки + временный доступ ученика до зачисления.
 *
 * @package Inc\Migrations
 *
 * ### Колонки
 *
 * - `applications.expires_at` — срок жизни самой заявки (20 дней). Раньше его роль играл
 *   `join_code_expires_at`, и копирование ссылки сокращало жизнь заявки до 72 часов.
 * - `applications.trial_owner` — физлицо и учётку ученика завёл временный доступ: при его
 *   снятии они удаляются вместе с записью.
 * - `student_records.is_trial` — запись временного доступа (без родителя и договора).
 *
 * На новых установках колонки создаёт `Migration_1_0_0`; шаги идемпотентны.
 * Живым заявкам, ждущим родителя, срок отсчитывается с момента обновления: иначе заявки
 * старше 20 дней истекли бы на первом же тике cron.
 */
class Migration_1_0_32 implements MigrationInterface {

	public function up(): void {
		global $wpdb;

		$applications = TableName::Applications->prefixed();
		$records      = TableName::StudentRecords->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $this->hasColumn( $applications, 'expires_at' ) ) {
			$wpdb->query( "ALTER TABLE `$applications` ADD COLUMN `expires_at` datetime DEFAULT NULL AFTER `join_code_expires_at`" );
		}

		if ( ! $this->hasColumn( $applications, 'trial_owner' ) ) {
			$wpdb->query( "ALTER TABLE `$applications` ADD COLUMN `trial_owner` tinyint(1) NOT NULL DEFAULT 0 AFTER `expires_at`" );
		}

		if ( ! $this->hasColumn( $records, 'is_trial' ) ) {
			$wpdb->query( "ALTER TABLE `$records` ADD COLUMN `is_trial` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`" );
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE `$applications` SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 20 DAY) WHERE expires_at IS NULL AND status = %s",
			ApplicationStatus::PendingParent->value
		) );
		$wpdb->query( "UPDATE `$applications` SET expires_at = DATE_ADD(created_at, INTERVAL 20 DAY) WHERE expires_at IS NULL" );
		// phpcs:enable
	}

	public function down(): void {
		global $wpdb;

		$applications = TableName::Applications->prefixed();
		$records      = TableName::StudentRecords->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `$applications` DROP COLUMN IF EXISTS `expires_at`, DROP COLUMN IF EXISTS `trial_owner`" );
		$wpdb->query( "ALTER TABLE `$records` DROP COLUMN IF EXISTS `is_trial`" );
		// phpcs:enable
	}

	public function version(): string {
		return '1.0.32';
	}

	/**
	 * @param string $table  Таблица
	 * @param string $column Колонка
	 */
	private function hasColumn( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $column ) );
	}
}
