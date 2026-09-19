<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Services\Subject\PostTypeResolver;

/**
 * Class AssessmentSlugMigration
 *
 * Разовый перевод адресов уже созданных экзаменов на слаг-ID (см.
 * {@see \Inc\Services\Assessment\AssessmentSlugService}). Прямой `$wpdb` — не зависит от
 * регистрации CPT предметов; прежний слаг кладётся в `_wp_old_slug`, поэтому раздаваемые
 * ссылки продолжают открываться (WordPress редиректит со старого адреса).
 *
 * Правятся ДАННЫЕ, а не схема — миграция самодостаточна и version-gated собственной
 * опцией (паттерн {@see TaskFileSchemeMigration}).
 *
 * @package Inc\Migrations
 */
class AssessmentSlugMigration {

	private const VERSION_OPTION = 'fs_lms_assessment_slug_version';
	private const VERSION        = '1';

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM {$wpdb->posts}
				 WHERE post_type LIKE %s AND post_status NOT IN ( 'auto-draft', 'inherit', 'trash' )
				   AND post_name <> CAST( ID AS CHAR )",
				'%' . $wpdb->esc_like( PostTypeResolver::ASSESSMENTS_SUFFIX )
			),
			ARRAY_A
		);

		foreach ( $rows ?: array() as $row ) {
			$id = (int) $row['ID'];

			// WordPress сверяет старый слаг с раскодированным адресом запроса, а в БД
			// кириллица лежит percent-encoded — кладём оба вида, иначе старая ссылка даст 404.
			foreach ( array_unique( array( (string) $row['post_name'], urldecode( (string) $row['post_name'] ) ) ) as $oldSlug ) {
				if ( '' !== $oldSlug ) {
					add_post_meta( $id, '_wp_old_slug', $oldSlug, true );
				}
			}
			$wpdb->update( $wpdb->posts, array( 'post_name' => (string) $id ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
