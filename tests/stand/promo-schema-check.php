<?php
/** Проверка общей схемы и чтения назначений на отдельном префиксе MariaDB. */

declare( strict_types=1 );

use Inc\Enums\Settings\TableName;
use Inc\Migrations\PromoSchema;
use Inc\Repositories\WPDBRepositories\Promo\PromoAccountRepository;
use Inc\Repositories\WPDBRepositories\Promo\PromoCourseRepository;
use Inc\Services\Promo\CourseAssignmentResolver;

require dirname( __DIR__, 5 ) . '/wp-load.php';

global $wpdb;
$originalPrefix = $wpdb->prefix;
$wpdb->prefix = 'zzpromo_' . bin2hex( random_bytes( 6 ) ) . '_';
$tables = array( TableName::PromoEventLinks, TableName::PromoEvents, TableName::PromoAccounts );
$failed = false;

try {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( PromoSchema::ddl( $wpdb->get_charset_collate() ) as $ddl ) {
		dbDelta( $ddl );
	}
	foreach ( PromoSchema::ddl( $wpdb->get_charset_collate() ) as $ddl ) {
		dbDelta( $ddl );
	}
	$schema = new PromoSchema();
	$columns = new ReflectionMethod( PromoSchema::class, 'verifyColumns' );
	$indexes = new ReflectionMethod( PromoSchema::class, 'verifyIndexes' );
	$accountTable = TableName::PromoAccounts->prefixed();
	$checkColumn = static function () use ( $columns, $schema ): void {
		$columns->invoke( $schema, TableName::PromoAccounts, array(
			'id' => array( 'bigint unsigned', 'NO', null, 'auto_increment' ),
			'user_id' => array( 'bigint unsigned', 'NO', null, '' ),
			'origin_id' => array( 'bigint unsigned', 'YES', null, '' ),
			'expires_at' => array( 'datetime', 'NO', null, '' ),
			'revoked_at' => array( 'datetime', 'YES', null, '' ),
		) );
	};
	$checkIndex = static function () use ( $indexes, $schema ): void {
		$indexes->invoke( $schema, TableName::PromoAccounts, array(
			'PRIMARY' => array( true, array( 'id' ) ),
			'user_id' => array( true, array( 'user_id' ) ),
		) );
	};
	$rejected = static function ( callable $check ): bool {
		try {
			$check();
			return false;
		} catch ( RuntimeException $e ) {
			return true;
		}
	};
	$schemaChecks = array();
	$checkColumn();
	$checkIndex();
	$wpdb->query( "ALTER TABLE `$accountTable` MODIFY user_id int unsigned NOT NULL" );
	$schemaChecks['wrong type rejected'] = $rejected( $checkColumn );
	$wpdb->query( "ALTER TABLE `$accountTable` MODIFY user_id bigint(20) unsigned NULL DEFAULT 7" );
	$schemaChecks['wrong null and default rejected'] = $rejected( $checkColumn );
	$wpdb->query( "ALTER TABLE `$accountTable` MODIFY user_id bigint(20) unsigned NOT NULL" );
	$wpdb->query( "ALTER TABLE `$accountTable` MODIFY id bigint(20) unsigned NOT NULL" );
	$schemaChecks['missing auto increment rejected'] = $rejected( $checkColumn );
	$wpdb->query( "ALTER TABLE `$accountTable` MODIFY id bigint(20) unsigned NOT NULL AUTO_INCREMENT" );
	$wpdb->query( "ALTER TABLE `$accountTable` DROP INDEX user_id, ADD KEY user_id (user_id)" );
	$schemaChecks['nonunique index rejected'] = $rejected( $checkIndex );
	$wpdb->query( "ALTER TABLE `$accountTable` DROP INDEX user_id, ADD UNIQUE KEY user_id (user_id)" );
	$wpdb->query( "ALTER TABLE `$accountTable` ENGINE=MyISAM" );
	$schemaChecks['nontransactional engine rejected'] = $rejected( $checkIndex );
	$wpdb->query( "ALTER TABLE `$accountTable` ENGINE=InnoDB" );
	foreach ( $schemaChecks as $name => $ok ) {
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
		$failed = $failed || ! $ok;
	}
	$now = '2026-11-03 12:00:00';
	$accounts = new PromoAccountRepository( $wpdb );
	$accounts->createAccount( 123, 456, 'example', '2026-11-04 12:00:00', $now );
	$accounts->createEvent( 'example', 1, $now );
	$accounts->createLink( 1, 1, 12, '2026-11-04 12:00:00', $now );
	$wpdb->update( TableName::PromoEvents->prefixed(), array( 'course_id' => 42 ), array( 'id' => 1 ) );
	$repo = new PromoCourseRepository( new CourseAssignmentResolver(), $wpdb );
	$checks = array();
	$checks['inherited'] = 42 === $repo->effectiveCourse( 1, $now );
	$wpdb->update( TableName::PromoEventLinks->prefixed(), array( 'course_override' => 1, 'course_id' => 99 ), array( 'id' => 1 ) );
	$checks['individual'] = 99 === $repo->effectiveCourse( 1, $now );
	$wpdb->update( TableName::PromoEventLinks->prefixed(), array( 'course_id' => null ), array( 'id' => 1 ) );
	$checks['explicit revoke'] = null === $repo->effectiveCourse( 1, $now );
	$wpdb->update( TableName::PromoEventLinks->prefixed(), array( 'course_override' => 0 ), array( 'id' => 1 ) );
	$checks['expiry boundary'] = null === $repo->effectiveCourse( 1, '2026-11-04 12:00:00' );
	$wpdb->update( TableName::PromoAccounts->prefixed(), array( 'revoked_at' => $now ), array( 'id' => 1 ) );
	$checks['account revoked'] = null === $repo->effectiveCourse( 1, $now );
	$wpdb->suppress_errors( true );
	$duplicate = false;
	try {
		$accounts->createAccount( 123, 789, 'example', '2026-11-04 12:00:00', $now );
	} catch ( \Inc\Repositories\WPDBRepositories\DuplicateKeyException $e ) {
		$duplicate = true;
	}
	$wpdb->suppress_errors( false );
	$checks['unique account user'] = $duplicate;
	foreach ( $checks as $name => $ok ) {
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
		$failed = $failed || ! $ok;
	}
} finally {
	foreach ( $tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table->prefixed() . '`' );
	}
	$wpdb->prefix = $originalPrefix;
}

exit( $failed ? 1 : 0 );
