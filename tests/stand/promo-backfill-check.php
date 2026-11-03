<?php
/** Проверка повторяемого переноса существующих аккаунтов на временных таблицах. */

declare( strict_types=1 );

use Inc\Enums\Settings\TableName;
use Inc\Migrations\PromoSchema;
use Inc\Modules\Masterclass\Repositories\PromoBackfillRepository;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;

require dirname( __DIR__, 5 ) . '/wp-load.php';
global $wpdb;

$oldPrefix = $wpdb->prefix;
$wpdb->prefix = 'zzpromo_' . bin2hex( random_bytes( 6 ) ) . '_';
$tables = array( TableName::PromoEventLinks->prefixed(), TableName::PromoEvents->prefixed(), TableName::PromoAccounts->prefixed(), MasterclassSchema::accounts(), MasterclassSchema::masterclasses() );
$failed = false;

try {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();
	$schema = new MasterclassSchema();
	dbDelta( $schema->masterclassesDdl( $collate ) );
	dbDelta( $schema->accountsDdl( $collate ) );
	foreach ( PromoSchema::ddl( $collate ) as $sql ) {
		dbDelta( $sql );
	}
	$now = '2026-11-03 12:00:00';
	$freeze = '2026-11-13 12:00:00';
	$wpdb->insert( MasterclassSchema::masterclasses(), array( 'id' => 7, 'school_name' => 'School', 'subject_key' => 'math', 'lesson_id' => 3, 'teacher_user_id' => 4, 'starts_at' => $now, 'ends_at' => $now, 'freeze_at' => $freeze, 'unused_purge_at' => $freeze, 'purge_at' => $freeze, 'created_at' => $now, 'updated_at' => $now ) );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 81, 'masterclass_id' => 7, 'user_id' => 123, 'person_id' => 456, 'student_record_id' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 82, 'masterclass_id' => 7, 'created_at' => $now, 'updated_at' => $now ) );
	$backfill = new PromoBackfillRepository( $wpdb );
	$checks = array();
	$wpdb->query( 'ALTER TABLE `' . MasterclassSchema::accounts() . '` ENGINE=MyISAM' );
	$refused = false;
	try {
		$backfill->attachAtomic( 82, 900, 901, 1, $now );
	} catch ( RuntimeException $e ) {
		$refused = true;
	}
	$checks['nontransactional source rejected before write'] = $refused && null === $wpdb->get_var( 'SELECT user_id FROM `' . MasterclassSchema::accounts() . '` WHERE id = 82' );
	$wpdb->query( 'ALTER TABLE `' . MasterclassSchema::accounts() . '` ENGINE=InnoDB' );
	$checks['first batch cursor'] = 82 === $backfill->copyBatch( 0, 100 );
	$checks['repeat cursor'] = 82 === $backfill->copyBatch( 0, 100 );
	$accounts = $wpdb->get_results( 'SELECT * FROM `' . TableName::PromoAccounts->prefixed() . '`', ARRAY_A );
	$links = $wpdb->get_results( 'SELECT * FROM `' . TableName::PromoEventLinks->prefixed() . '`', ARRAY_A );
	$checks['no duplicates'] = 1 === count( $accounts ) && 1 === count( $links );
	$checks['identity and expiry'] = 123 === (int) $accounts[0]['user_id'] && 456 === (int) $accounts[0]['person_id'] && $freeze === $links[0]['expires_at'];
	$atomicFreeze = '2026-11-15 12:00:00';
	$wpdb->query( 'START TRANSACTION' );
	$backfill->updateEventTogether( 7, array( 'freeze_at' => $atomicFreeze, 'updated_at' => $now ) );
	$checks['deadline update propagates'] = $atomicFreeze === $wpdb->get_var( 'SELECT expires_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 81' ) && $atomicFreeze === $wpdb->get_var( 'SELECT expires_at FROM `' . TableName::PromoAccounts->prefixed() . '` WHERE user_id = 123' );
	$wpdb->query( 'ROLLBACK' );
	$checks['deadline update rolls back with caller'] = $freeze === $wpdb->get_var( 'SELECT freeze_at FROM `' . MasterclassSchema::masterclasses() . '` WHERE id = 7' ) && $freeze === $wpdb->get_var( 'SELECT expires_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 81' );
	$checks['legacy number preserved'] = 81 === (int) $links[0]['participant_id'] && 123 === (int) $wpdb->get_var( 'SELECT user_id FROM `' . MasterclassSchema::accounts() . '` WHERE id = 81' );
	$extended = '2026-11-20 12:00:00';
	$wpdb->update( MasterclassSchema::masterclasses(), array( 'freeze_at' => $extended ), array( 'id' => 7 ) );
	$backfill->copyBatch( 0, 100 );
	$checks['extension updates inherited deadlines'] = $extended === $wpdb->get_var( 'SELECT expires_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 81' ) && $extended === $wpdb->get_var( 'SELECT expires_at FROM `' . TableName::PromoAccounts->prefixed() . '` WHERE user_id = 123' );
	$individual = '2026-11-17 12:00:00';
	$wpdb->update( TableName::PromoEventLinks->prefixed(), array( 'expires_at' => $individual ), array( 'participant_id' => 81 ) );
	$wpdb->update( MasterclassSchema::masterclasses(), array( 'freeze_at' => '2026-11-25 12:00:00' ), array( 'id' => 7 ) );
	$backfill->copyBatch( 0, 100 );
	$checks['individual deadline is preserved'] = $individual === $wpdb->get_var( 'SELECT expires_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 81' );
	$wpdb->update( TableName::PromoAccounts->prefixed(), array( 'person_id' => 999 ), array( 'user_id' => 123 ) );
	$conflict = false;
	try {
		$backfill->copyBatch( 0, 100 );
	} catch ( RuntimeException $e ) {
		$conflict = true;
	}
	$checks['identity conflict is rejected'] = $conflict && 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . TableName::PromoEventLinks->prefixed() . '`' );
	$wpdb->update( TableName::PromoAccounts->prefixed(), array( 'person_id' => 456 ), array( 'user_id' => 123 ) );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 83, 'masterclass_id' => 7, 'user_id' => 777, 'person_id' => 888, 'created_at' => $now, 'updated_at' => $now ) );
	$accountsRepo = new \Inc\Repositories\WPDBRepositories\Promo\PromoAccountRepository( $wpdb );
	$otherAccount = $accountsRepo->createAccount( 444, 555, 'example', $freeze, $now );
	$wpdb->insert( TableName::PromoEventLinks->prefixed(), array( 'account_id' => $otherAccount, 'event_id' => 1, 'participant_id' => 83, 'expires_at' => $freeze, 'created_at' => $now, 'updated_at' => $now ) );
	$failedCopy = false;
	try {
		$backfill->copyBatch( 82, 1 );
	} catch ( Throwable $e ) {
		$failedCopy = true;
	}
	$checks['partial insert rolled back'] = $failedCopy && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . TableName::PromoAccounts->prefixed() . '` WHERE user_id = 777' );
	$backfill->attachAtomic( 82, 900, 901, 1, $now );
	$lateLink = $wpdb->get_row( 'SELECT * FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 82', ARRAY_A );
	$checks['attach after cursor is synchronised'] = is_array( $lateLink ) && null === $lateLink['revoked_at'];
	$collision = false;
	try {
		$backfill->attachAtomic( 82, 999, 901, 1, $now );
	} catch ( RuntimeException $e ) {
		$collision = true;
	}
	$checks['attach rejects identity replacement'] = $collision && 900 === (int) $wpdb->get_var( 'SELECT user_id FROM `' . MasterclassSchema::accounts() . '` WHERE id = 82' );
	$missing = false;
	try {
		$backfill->attachAtomic( 9999, 999, 901, 1, $now );
	} catch ( RuntimeException $e ) {
		$missing = true;
	}
	$checks['attach rejects missing participant'] = $missing;
	$wpdb->update( MasterclassSchema::accounts(), array( 'student_record_id' => null, 'updated_at' => $extended ), array( 'id' => 82 ) );
	$backfill->copyBatch( 81, 1 );
	$checks['detached link revoked on replay'] = $extended === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 82' );
	$backfill->deleteAtomic( 82, \Inc\Modules\Masterclass\Enums\AccountDeleteReason::Unused, $now );
	$checks['delete preserves earlier revocation'] = $extended === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 82' );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 85, 'masterclass_id' => 7, 'user_id' => 990, 'person_id' => 991, 'deleted_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
	$backfill->copyBatch( 84, 1 );
	$checks['historically deleted account stays revoked'] = $now === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 85' );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 86, 'masterclass_id' => 7, 'user_id' => 992, 'person_id' => 993, 'student_record_id' => 3, 'state' => 'enrolled', 'created_at' => $now, 'updated_at' => $extended ) );
	$backfill->copyBatch( 85, 1 );
	$checks['historically enrolled account stays revoked'] = $extended === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 86' );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 87, 'masterclass_id' => 7, 'user_id' => 994, 'person_id' => 995, 'student_record_id' => 4, 'created_at' => $now, 'updated_at' => $now ) );
	$backfill->copyBatch( 86, 1 );
	$wpdb->query( 'START TRANSACTION' );
	$deletedTogether = $backfill->markDeletedTogether( 87, \Inc\Modules\Masterclass\Enums\AccountDeleteReason::Unused, $now );
	$wpdb->query( 'ROLLBACK' );
	$checks['caller transaction rolls back source and link'] = $deletedTogether && null === $wpdb->get_var( 'SELECT deleted_at FROM `' . MasterclassSchema::accounts() . '` WHERE id = 87' ) && null === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 87' );
	$backfill->markDeletedTogether( 87, \Inc\Modules\Masterclass\Enums\AccountDeleteReason::Unused, $now );
	$checks['live deletion revokes link'] = $now === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 87' );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 88, 'masterclass_id' => 7, 'user_id' => 996, 'person_id' => 997, 'student_record_id' => 5, 'created_at' => $now, 'updated_at' => $now ) );
	$backfill->copyBatch( 87, 1 );
	$backfill->detachTogether( 88, $now );
	$checks['live detach revokes link'] = null !== $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 88' ) && null === $wpdb->get_var( 'SELECT student_record_id FROM `' . MasterclassSchema::accounts() . '` WHERE id = 88' );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 89, 'masterclass_id' => 7, 'user_id' => 998, 'person_id' => 999, 'student_record_id' => 6, 'created_at' => $now, 'updated_at' => $now ) );
	$backfill->copyBatch( 88, 1 );
	$backfill->detachTogether( 89, $now, true );
	$checks['live enrollment revokes link'] = null !== $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 89' ) && 'enrolled' === $wpdb->get_var( 'SELECT state FROM `' . MasterclassSchema::accounts() . '` WHERE id = 89' );
	$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => 84, 'masterclass_id' => 7, 'created_at' => $now, 'updated_at' => $now ) );
	$wpdb->query( 'DROP TABLE `' . TableName::PromoEventLinks->prefixed() . '`' );
	$wpdb->suppress_errors( true );
	$writeFailed = false;
	try {
		$backfill->attachAtomic( 84, 901, 902, 2, $now );
	} catch ( Throwable $e ) {
		$writeFailed = true;
	}
	$wpdb->suppress_errors( false );
	$checks['failed attach rolls back source'] = $writeFailed && null === $wpdb->get_var( 'SELECT user_id FROM `' . MasterclassSchema::accounts() . '` WHERE id = 84' );
	$wpdb->suppress_errors( true );
	$deleteFailed = false;
	try {
		$backfill->deleteAtomic( 81, \Inc\Modules\Masterclass\Enums\AccountDeleteReason::Unused, $now );
	} catch ( Throwable $e ) {
		$deleteFailed = true;
	}
	$wpdb->suppress_errors( false );
	$checks['failed deletion rolls back source'] = $deleteFailed && null === $wpdb->get_var( 'SELECT deleted_at FROM `' . MasterclassSchema::accounts() . '` WHERE id = 81' );
	foreach ( PromoSchema::ddl( $collate ) as $sql ) {
		dbDelta( $sql );
	}
	$retried = $backfill->deleteAtomic( 81, \Inc\Modules\Masterclass\Enums\AccountDeleteReason::Unused, $now );
	$checks['deletion retry succeeds and revokes'] = $retried && $now === $wpdb->get_var( 'SELECT revoked_at FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id = 81' );
	$wpdb->insert( MasterclassSchema::masterclasses(), array( 'id' => 8, 'school_name' => 'Race', 'subject_key' => 'math', 'lesson_id' => 3, 'teacher_user_id' => 4, 'starts_at' => $now, 'ends_at' => $now, 'freeze_at' => $freeze, 'unused_purge_at' => $freeze, 'purge_at' => $freeze, 'created_at' => $now, 'updated_at' => $now ) );
	foreach ( array( 90, 91 ) as $id ) {
		$wpdb->insert( MasterclassSchema::accounts(), array( 'id' => $id, 'masterclass_id' => 8, 'user_id' => 1000 + $id, 'person_id' => 2000 + $id, 'student_record_id' => $id, 'created_at' => $now, 'updated_at' => $now ) );
	}
	$gate = sys_get_temp_dir() . '/promo-race-' . bin2hex( random_bytes( 8 ) );
	$workers = array();
	foreach ( array( 89, 90 ) as $cursor ) {
		$pipes = array();
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/promo-backfill-race-worker.php', $wpdb->prefix, $gate, (string) $cursor ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$workers[] = array( $process, $pipes );
	}
	touch( $gate );
	$success = true;
	foreach ( $workers as [ $process, $pipes ] ) {
		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		if ( 0 !== $code ) {
			$success = false;
			echo $err;
		}
	}
	unlink( $gate );
	$checks['parallel event creation'] = $success && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `" . TableName::PromoEvents->prefixed() . "` WHERE source_type = 'masterclass' AND source_id = 8" ) && 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . TableName::PromoEventLinks->prefixed() . '` WHERE participant_id IN (90,91)' );
	foreach ( $checks as $name => $ok ) {
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
		$failed = $failed || ! $ok;
	}
} finally {
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}
	$wpdb->prefix = $oldPrefix;
}
exit( $failed ? 1 : 0 );
