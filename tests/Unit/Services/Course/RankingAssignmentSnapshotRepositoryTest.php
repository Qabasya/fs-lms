<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;
use PHPUnit\Framework\TestCase;

class RankingAssignmentSnapshotRepositoryTest extends TestCase {
	public function test_read_error_does_not_replace_existing_snapshots(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = $this->createMock( \wpdb::class );
		$db->options = 'wp_options';
		$db->last_error = 'read failed';
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$db->method( 'get_var' )->willReturn( null );
		$db->expects( self::never() )->method( 'query' );
		$GLOBALS['wpdb'] = $db;
		try {
			$this->expectException( \RuntimeException::class );
			( new RankingAssignmentSnapshotRepository() )->addMissing( 42, array( 17 => array( 'issued_at' => '2026-11-30 10:00:00' ) ) );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
	public function test_snapshot_commits_before_work_change_while_lock_is_held(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = $this->createMock( \wpdb::class );
		$db->options = 'wp_options';
		$events = array();
		$db->method( 'prepare' )->willReturnCallback( static fn( string $sql, ...$args ): string => $sql );
		$db->method( 'get_var' )->willReturnCallback( static function ( string $sql ) use ( &$events ): string {
			$events[] = str_contains( $sql, 'RELEASE_LOCK' ) ? 'unlock' : 'lock';
			return '1';
		} );
		$db->method( 'query' )->willReturnCallback( static function ( string $sql ) use ( &$events ): int {
			$events[] = $sql;
			return 1;
		} );
		$GLOBALS['wpdb'] = $db;
		try {
			( new RankingAssignmentSnapshotRepository() )->synchronizedWrite(
				static function () use ( &$events ): void { $events[] = 'snapshot'; },
				static function () use ( &$events ): void { $events[] = 'write'; }
			);
			self::assertSame( array( 'lock', 'START TRANSACTION', 'snapshot', 'COMMIT', 'write', 'unlock' ), $events );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
	public function test_completed_issue_replaces_pending_but_preserves_existing_issue(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = $this->createMock( \wpdb::class );
		$db->options = 'wp_options';
		$db->method( 'get_var' )->willReturn( serialize( array( 42 => array(
			17 => array( 'issued_at' => '2026-11-30 10:00:00', 'pending' => true ),
			18 => array( 'issued_at' => '2026-11-29 10:00:00', 'source' => 'homework', 'task_points' => array( 2 => 1 ) ),
		) ) ) );
		$saved = null;
		$db->method( 'prepare' )->willReturnCallback( static function ( string $sql, ...$args ) use ( &$saved ): string {
			if ( str_starts_with( $sql, 'INSERT' ) ) {
				$saved = unserialize( $args[2] );
			}
			return $sql;
		} );
		$db->method( 'query' )->willReturn( 1 );
		$GLOBALS['wpdb'] = $db;
		try {
			( new RankingAssignmentSnapshotRepository() )->addMissing( 42, array(
				17 => array( 'issued_at' => '2026-12-02 10:00:00', 'source' => 'homework', 'task_points' => array( 1 => 2 ) ),
				18 => array( 'issued_at' => '2026-12-02 10:00:00', 'source' => 'homework', 'task_points' => array( 2 => 3 ) ),
			) );
			self::assertSame( '2026-12-02 10:00:00', $saved[42][17]['issued_at'] );
			self::assertSame( array( 1 => 2 ), $saved[42][17]['task_points'] );
			self::assertSame( '2026-11-29 10:00:00', $saved[42][18]['issued_at'] );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

	public function test_failed_assignment_rolls_back_and_releases_lock(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$db = $this->createMock( \wpdb::class );
		$db->options = 'wp_options';
		$db->method( 'prepare' )->willReturnCallback( static fn( string $sql, ...$args ): string => $sql );
		$db->method( 'get_var' )->willReturn( '1' );
		$queries = array();
		$db->method( 'query' )->willReturnCallback( static function ( string $query ) use ( &$queries ): int {
			$queries[] = $query;
			return 1;
		} );
		$GLOBALS['wpdb'] = $db;
		try {
			( new RankingAssignmentSnapshotRepository() )->synchronized( static function (): void {
				throw new \RuntimeException( 'write failed' );
			} );
			self::fail( 'Failure must propagate.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'write failed', $error->getMessage() );
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $queries );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}
}

namespace Inc\Repositories\OptionsRepositories;

function maybe_unserialize( mixed $value ): mixed {
	return is_string( $value ) ? unserialize( $value ) : $value;
}

function maybe_serialize( mixed $value ): string {
	return serialize( $value );
}
