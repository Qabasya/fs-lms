<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Settings\OptionName;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\PostMetaName;

/**
 * Class CommonTemplateMigration
 *
 * Разовый перевод заданий с удалённого шаблона «Стандартное задание с общим
 * условием» (`common_standard_task`) на «Стандартное задание»: общее условие
 * теперь необязательное поле любого шаблона, а набор полей у них совпадает,
 * поэтому `fs_lms_meta` переезжает как есть.
 *
 * Правятся ДАННЫЕ, а не схема, поэтому миграция самодостаточна и version-gated
 * собственной опцией (паттерн {@see TaskFileSchemeMigration}). Прямой `$wpdb` по
 * `postmeta` — не зависит от регистрации CPT предметов.
 *
 * @package Inc\Migrations
 */
class CommonTemplateMigration {

	private const VERSION_OPTION = 'fs_lms_common_template_version';
	private const VERSION        = '1';
	private const LEGACY_ID      = 'common_standard_task';

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->migratePosts();
		$this->migrateAssignments();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private function migratePosts(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => TaskTemplate::Standard->value ),
			array(
				'meta_key'   => PostMetaName::TemplateType->value,
				'meta_value' => self::LEGACY_ID,
			)
		);
	}

	/** Привязки «номер задания → шаблон» в `wp_options`: [предмет][номер] => template_id. */
	private function migrateAssignments(): void {
		$all = get_option( OptionName::Metaboxes->value, array() );
		if ( ! is_array( $all ) ) {
			return;
		}

		$changed = false;
		foreach ( $all as $subject => $tasks ) {
			if ( ! is_array( $tasks ) ) {
				continue;
			}
			foreach ( $tasks as $number => $templateId ) {
				if ( self::LEGACY_ID === $templateId ) {
					$all[ $subject ][ $number ] = TaskTemplate::Standard->value;
					$changed                    = true;
				}
			}
		}

		if ( $changed ) {
			update_option( OptionName::Metaboxes->value, $all );
		}
	}
}
