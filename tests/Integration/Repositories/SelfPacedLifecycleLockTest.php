<?php
declare( strict_types=1 );
namespace Integration\Repositories;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use PHPUnit\Framework\TestCase;
class SelfPacedLifecycleLockTest extends TestCase {
	public function test_nested_operation_uses_one_lock_and_releases_after_failure(): void {
		$db = new \FakeWpdb(); $db->queueVar( 'isolated_test' );
		$groups = new GroupsRepository( $db );
		$called = false;
		try {
			$groups->withSelfPacedLifecycleLock( function() use ( $groups, &$called ): void {
				$groups->withSelfPacedLifecycleLock( function() use ( &$called ): void { $called = true; throw new \DomainException( 'stop' ); } );
			} );
			self::fail( 'Expected failure' );
		} catch ( \DomainException $error ) { self::assertSame( 'stop', $error->getMessage() ); }
		self::assertTrue( $called );
		self::assertCount( 2, $db->lockQueries );
		self::assertStringContainsString( 'GET_LOCK', $db->lockQueries[0] );
		self::assertStringContainsString( 'RELEASE_LOCK', $db->lockQueries[1] );
	}
	public function test_failed_acquisition_never_enters_operation_or_releases_unowned_lock(): void {
		$db = new class extends \FakeWpdb {
			public function get_var( string $query, int $x = 0, int $y = 0 ): mixed {
				$this->queries[] = $query;
				return 'SELECT DATABASE()' === $query ? 'isolated_test' : 0;
			}
		};
		$called = false;
		try { ( new GroupsRepository( $db ) )->withSelfPacedLifecycleLock( function() use ( &$called ): void { $called = true; } ); self::fail( 'Expected refusal' ); }
		catch ( \DomainException $error ) { self::assertStringContainsString( 'занят', $error->getMessage() ); }
		self::assertFalse( $called );
		self::assertCount( 2, $db->queries );
	}
	public function test_missing_database_identity_does_not_acquire_a_global_lock(): void {
		$db = new \FakeWpdb();
		$this->expectException( \RuntimeException::class );
		( new GroupsRepository( $db ) )->withSelfPacedLifecycleLock( static fn(): bool => true );
	}
}
