<?php

declare( strict_types=1 );

namespace Integration\Repositories\Exam;

use FakeWpdb;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use PHPUnit\Framework\TestCase;

/** Записи: закрытие только действующей записи с освобождением активного слота, пересечение по времени между проведениями. */
class ExamRegistrationRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private ExamRegistrationRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new ExamRegistrationRepository( $this->wpdb );
	}

	public function test_deactivate_clears_active_slot(): void {
		$this->repo->deactivate( 20, ExamRegistrationStatus::Cancelled, '2026-03-10 07:00:00' );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "status = 'cancelled'", $sql );
		self::assertStringContainsString( 'active_slot = NULL', $sql );
		self::assertStringContainsString( "cancelled_at = '2026-03-10 07:00:00'", $sql );
	}

	public function test_deactivate_only_active_row(): void {
		$this->wpdb->queueQuery( 0 );

		self::assertFalse( $this->repo->deactivate( 20, ExamRegistrationStatus::Cancelled, '2026-03-10 07:00:00' ) );
		self::assertStringContainsString( 'WHERE id = 20 AND active_slot = 1', $this->wpdb->lastQuery() );
	}

	public function test_deactivate_writes_status_specific_timestamp(): void {
		$this->repo->deactivate( 20, ExamRegistrationStatus::Transferred, '2026-03-10 07:00:00' );
		self::assertStringContainsString( 'transferred_at', $this->wpdb->lastQuery() );

		$this->repo->deactivate( 20, ExamRegistrationStatus::Missed, '2026-03-10 07:00:00' );
		self::assertStringContainsString( 'missed_at', $this->wpdb->lastQuery() );
	}

	public function test_deactivate_records_reason_and_actor_when_given(): void {
		$this->repo->deactivate( 20, ExamRegistrationStatus::Cancelled, '2026-03-10 07:00:00', 'Болеет', 9 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( "reason = 'Болеет'", $sql );
		self::assertStringContainsString( 'actor_user_id = 9', $sql );
	}

	public function test_active_registration_cannot_be_deactivated_into_confirmed(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->repo->deactivate( 20, ExamRegistrationStatus::Confirmed, '2026-03-10 07:00:00' );
	}

	public function test_overlap_query_excludes_current_event_and_compares_session_windows(): void {
		$this->wpdb->queueVar( 1 );

		$overlaps = $this->repo->hasOverlappingActive( 4, '2026-03-12 07:00:00', '2026-03-12 10:55:00', 1 );

		$sql = $this->wpdb->lastQuery();
		self::assertTrue( $overlaps );
		self::assertStringContainsString( 'r.active_slot = 1', $sql );
		self::assertStringContainsString( 'p.participant_id = 4', $sql );
		self::assertStringContainsString( 'p.event_id <> 1', $sql );
		self::assertStringContainsString( "s.scheduled_at < '2026-03-12 10:55:00'", $sql );
		self::assertStringContainsString( "s.planned_end_at > '2026-03-12 07:00:00'", $sql );
	}

	public function test_overlap_false_when_nothing_counted(): void {
		$this->wpdb->queueVar( 0 );

		self::assertFalse( $this->repo->hasOverlappingActive( 4, '2026-03-12 07:00:00', '2026-03-12 10:55:00', 1 ) );
	}

	public function test_active_registrations_of_event_are_counted_through_its_sessions(): void {
		$this->wpdb->queueVar( '3' );

		self::assertSame( 3, $this->repo->countActiveByEvent( 3 ) );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 's.event_id = 3', $sql );
		self::assertStringContainsString( 'r.active_slot = 1', $sql );
	}

	public function test_list_by_session_without_statuses_returns_all(): void {
		$this->repo->listBySession( 100 );

		self::assertStringNotContainsString( 'status IN', $this->wpdb->lastQuery() );
	}

	public function test_list_by_session_filters_by_given_statuses(): void {
		$this->repo->listBySession( 100, array( ExamRegistrationStatus::Confirmed, ExamRegistrationStatus::Missed ) );

		self::assertStringContainsString( "status IN ( 'confirmed', 'missed' )", $this->wpdb->lastQuery() );
	}

	public function test_candidates_for_no_show_are_active_registrations_of_ended_sessions(): void {
		$this->repo->listActiveOfEndedSessions( '2026-03-12 11:00:00', 50 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'r.active_slot = 1', $sql );
		self::assertStringContainsString( "s.planned_end_at <= '2026-03-12 11:00:00'", $sql );
		self::assertStringContainsString( 'LIMIT 50', $sql );
	}
}
