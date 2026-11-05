<?php
/** Preserve the direction that originally granted independent course access. */
declare( strict_types=1 );
namespace Inc\Migrations;
use Inc\Contracts\MigrationInterface;
use Inc\Enums\Settings\TableName;
class Migration_1_0_106 implements MigrationInterface {
	public function up(): void {
		global $wpdb;
		$table = TableName::StudentRecords->prefixed();
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'self_paced_basis_subject' ) )
			&& false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN self_paced_basis_subject varchar(191) DEFAULT NULL', $table ) ) ) {
			throw new \RuntimeException( 'Не удалось сохранить основание доступа к самостоятельному курсу.' );
		}
	}
	public function down(): void {}
	public function version(): string { return '1.0.106'; }
}
