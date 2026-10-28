<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/**
 * Class Migration_1_0_104
 *
 * Место ученика на экзамене: `exam_registrations.seat_no` — номер места от 1 до вместимости сеанса, который сотрудник
 * отмечает на доске проведения после отметки прихода. Уникальный ключ `(session_id, seat_no, active_slot)` не даёт посадить
 * двоих на одно место: у закрытых записей `active_slot` пуст, поэтому их старые места ключ не занимают, а у записей без места
 * `seat_no` пуст, и они друг другу не мешают.
 *
 * Переносимый SQL: проверка `SHOW COLUMNS` / `SHOW INDEX` и обычный `ADD` (образец — {@see Migration_1_0_94}). Идемпотентна.
 *
 * @package Inc\Migrations
 */
class Migration_1_0_104 implements MigrationInterface {

	public const SEAT_KEY = 'session_seat';

	public function up(): void {
		global $wpdb;

		$table = TableName::ExamRegistrations->prefixed();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", 'seat_no' ) ) ) {
			$wpdb->query( Migration_1_0_94::addColumnSql( $table, 'seat_no', 'tinyint unsigned DEFAULT NULL AFTER `arrived_by_user_id`' ) );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `$table` WHERE Key_name = %s", self::SEAT_KEY ) ) ) {
			$wpdb->query( 'ALTER TABLE `' . $table . '` ADD UNIQUE KEY `' . self::SEAT_KEY . '` (`session_id`, `seat_no`, `active_slot`)' );
		}
		// phpcs:enable
	}

	public function down(): void {
		// Колонка и ключ безвредны: откат не нужен.
	}

	public function version(): string {
		return '1.0.104';
	}
}
