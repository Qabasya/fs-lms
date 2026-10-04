<?php

declare( strict_types=1 );

namespace Integration\Repositories\Exam;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use PHPUnit\Framework\TestCase;

/** Заявки гостей: флаг брони снимается одним условным запросом — гарантия «место освобождается ровно один раз». */
class ExamGuestApplicationRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private ExamGuestApplicationRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new ExamGuestApplicationRepository( $this->wpdb );
	}

	public function test_release_held_flag_is_conditional_on_flag_being_set(): void {
		$this->repo->releaseHeldFlag( 9 );

		self::assertStringContainsString( 'SET is_held = 0 WHERE id = 9 AND is_held = 1', $this->wpdb->lastQuery() );
	}

	public function test_release_held_flag_true_only_for_the_call_that_flipped_it(): void {
		$this->wpdb->queueQuery( 1 );
		$this->wpdb->queueQuery( 0 );

		self::assertTrue( $this->repo->releaseHeldFlag( 9 ) );
		self::assertFalse( $this->repo->releaseHeldFlag( 9 ), 'Повторный вызов флаг не меняет: место второй раз не освобождается.' );
	}

	public function test_expired_ids_are_selected_by_utc_now_oldest_first_with_limit(): void {
		$this->wpdb->queueCol( array( '5', '6' ) );

		$ids = $this->repo->listExpiredHeldIds( '2026-03-10 07:00:00', 25 );

		$sql = $this->wpdb->lastQuery();
		self::assertSame( array( 5, 6 ), $ids );
		self::assertStringContainsString( "is_held = 1 AND hold_expires_at <= '2026-03-10 07:00:00'", $sql );
		self::assertStringContainsString( 'ORDER BY hold_expires_at ASC', $sql );
		self::assertStringContainsString( 'LIMIT 25', $sql );
	}

	public function test_expired_ids_by_session_are_limited_to_that_session(): void {
		$this->repo->listExpiredHeldIdsBySession( 7, '2026-03-10 07:00:00' );

		self::assertStringContainsString( 'session_id = 7 AND is_held = 1', $this->wpdb->lastQuery() );
	}

	public function test_held_counters_count_only_active_holds(): void {
		$this->wpdb->queueVar( '3' );
		self::assertSame( 3, $this->repo->countHeldBySession( 7 ) );
		self::assertStringContainsString( 'session_id = 7 AND is_held = 1', $this->wpdb->lastQuery() );

		$this->wpdb->queueVar( '2' );
		self::assertSame( 2, $this->repo->countHeldBySource( 14 ) );
		self::assertStringContainsString( 'source_id = 14 AND is_held = 1', $this->wpdb->lastQuery() );

		$this->wpdb->queueVar( '1' );
		self::assertSame( 1, $this->repo->countHeldByIp( str_repeat( 'b', 64 ) ) );
		self::assertStringContainsString( "ip_hash = '" . str_repeat( 'b', 64 ) . "' AND is_held = 1", $this->wpdb->lastQuery() );
	}

	public function test_find_by_source_and_request_key_uses_the_unique_pair(): void {
		$this->repo->findBySourceAndRequestKey( 14, 'req-1' );

		self::assertStringContainsString( "source_id = 14 AND request_key = 'req-1'", $this->wpdb->lastQuery() );
	}

	public function test_find_for_update_locks_the_application(): void {
		$this->repo->findForUpdate( 9 );

		self::assertStringContainsString( 'WHERE id = 9 FOR UPDATE', $this->wpdb->lastQuery() );
	}

	public function test_identity_has_active_application_checks_the_active_slot_and_excludes_self(): void {
		$this->wpdb->queueVar( '1' );

		self::assertTrue( $this->repo->hasActiveByIdentity( 3, str_repeat( 'a', 64 ), 9 ) );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'event_id = 3', $sql );
		self::assertStringContainsString( 'active_slot = 1', $sql );
		self::assertStringContainsString( 'id <> 9', $sql );
	}

	public function test_update_is_versioned(): void {
		$this->wpdb->queueQuery( 0 );

		self::assertFalse( $this->repo->update( 9, array( 'state' => 'confirmed', 'active_slot' => null ), 4 ) );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'active_slot = NULL', $sql );
		self::assertStringContainsString( 'WHERE id = 9 AND version = 4', $sql );
	}
}
