<?php
/**
 * Add an atomic optional course-to-service-group relationship.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;

/** Existing groups retain NULL; no course is opted into independent learning. */
class Migration_1_0_105 implements MigrationInterface {
	/** Add the nullable unique relationship; fail before the runner marks success. */
	public function up(): void {
		global $wpdb;
		$table = TableName::Groups->prefixed();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'self_paced_course_id' ) ) ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN self_paced_course_id bigint unsigned DEFAULT NULL', $table ) ) ) {
				throw new \RuntimeException( 'Не удалось добавить связь самостоятельного курса.' );
			}
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'self_paced_course' ) ) ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY self_paced_course (self_paced_course_id)', $table ) ) ) {
				throw new \RuntimeException( 'Не удалось создать уникальную связь самостоятельного курса.' );
			}
		}
	}
	/** Retain the harmless column and its integrity constraint on rollback. */
	public function down(): void {}
	/** Schema version consumed by the normal migration runner. */
	public function version(): string {
		return '1.0.105'; }
}
