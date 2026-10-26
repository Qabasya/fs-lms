<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Settings\TableName;

/**
 * Class AccountDisplayNameMigration
 *
 * Приводит `display_name` WP-аккаунтов к единому правилу ({@see PersonDTO::accountName()}): у ученика «Фамилия Имя»,
 * у родителя полное ФИО. Раньше имя собиралось по-разному — с отчеством или без, а правка отчества отдельно имя аккаунта
 * не обновляла, из-за чего в ленте «Активность» и списках один ученик был с отчеством, а другой без.
 *
 * Трогает только аккаунты, привязанные к карточкам (`persons.wp_user_id`); преподаватели и сотрудники без карточки не затрагиваются.
 * Data-миграция, version-gated собственной опцией (паттерн {@see LearningEventActorMigration}).
 *
 * @package Inc\Migrations
 */
class AccountDisplayNameMigration {

	private const VERSION_OPTION = 'fs_lms_account_display_name_version';
	private const VERSION        = '1';

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->normalize();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private function normalize(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.*, u.display_name AS current_display_name FROM %i p INNER JOIN %i u ON u.ID = p.wp_user_id WHERE p.wp_user_id IS NOT NULL',
				TableName::Persons->prefixed(),
				$wpdb->users
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = PersonDTO::fromArray( $row )->accountName();
			// Пустое имя (обезличенная карточка) аккаунту не пишем.
			if ( '' === $name || $name === $row['current_display_name'] ) {
				continue;
			}

			$wpdb->update( $wpdb->users, array( 'display_name' => $name ), array( 'ID' => (int) $row['wp_user_id'] ) );
			clean_user_cache( (int) $row['wp_user_id'] );
		}
		// phpcs:enable
	}
}
