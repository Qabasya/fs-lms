<?php

declare( strict_types=1 );

namespace Integration\Repositories\Exam;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Сеансы: вместимость проверяет сама база (условные UPDATE), блокировки идут по возрастанию ID,
 * занятость кабинета не считает отменённые сеансы. SQL прогоняется через `prepare()` дубля.
 */
class ExamSessionRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;
	private ExamSessionRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new ExamSessionRepository( $this->wpdb );
	}

	/** @return array<string, mixed> */
	private function row( array $override = array() ): array {
		return array_merge( array(
			'id' => '7', 'event_id' => '3', 'assessment_id' => '500', 'scheduled_at' => '2026-03-10 07:00:00', 'planned_end_at' => '2026-03-10 10:55:00',
			'room_id' => '2', 'capacity' => '12', 'occupied_count' => '0', 'responsible_user_id' => '10', 'status' => 'open', 'first_started_at' => null,
			'cancel_reason' => null, 'version' => '1', 'created_at' => '2026-02-20 10:00:00', 'updated_at' => '2026-02-20 10:00:00',
		), $override );
	}

	public function test_occupy_seat_sql_is_conditional(): void {
		$this->repo->occupySeat( 7 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'occupied_count = occupied_count + 1', $sql );
		self::assertStringContainsString( 'occupied_count < capacity', $sql );
		self::assertStringContainsString( "status = 'open'", $sql );
		self::assertStringContainsString( 'id = 7', $sql );
		self::assertStringNotContainsString( 'version', $sql, 'Занятие места не меняет version.' );
	}

	public function test_occupy_returns_false_when_no_row_affected(): void {
		$this->wpdb->queueQuery( 0 );

		self::assertFalse( $this->repo->occupySeat( 7 ) );
	}

	public function test_occupy_returns_true_when_exactly_one_row_affected(): void {
		$this->wpdb->queueQuery( 1 );

		self::assertTrue( $this->repo->occupySeat( 7 ) );
	}

	public function test_release_seat_never_goes_below_zero(): void {
		$this->repo->releaseSeat( 7 );

		self::assertStringContainsString( 'occupied_count = occupied_count - 1', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'occupied_count > 0', $this->wpdb->lastQuery() );
	}

	public function test_release_returns_false_when_nothing_to_release(): void {
		$this->wpdb->queueQuery( 0 );

		self::assertFalse( $this->repo->releaseSeat( 7 ) );
	}

	public function test_lock_in_order_sorts_ids_ascending_and_locks(): void {
		$this->wpdb->queueResults( array( $this->row( array( 'id' => '3' ) ), $this->row( array( 'id' => '9' ) ) ) );

		$locked = $this->repo->lockInOrder( array( 9, 3, 9 ) );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'id IN ( 3, 9 )', $sql, 'Дубли убраны, ID по возрастанию.' );
		self::assertStringContainsString( 'ORDER BY id ASC FOR UPDATE', $sql );
		self::assertSame( array( 3, 9 ), array_keys( $locked ) );
	}

	public function test_lock_in_order_without_ids_does_not_query(): void {
		self::assertSame( array(), $this->repo->lockInOrder( array() ) );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_is_room_busy_excludes_cancelled_and_given_session(): void {
		$this->wpdb->queueVar( 0 );

		$busy = $this->repo->isRoomBusy( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00', 7 );

		$sql = $this->wpdb->lastQuery();
		self::assertFalse( $busy );
		self::assertStringContainsString( "status <> 'cancelled'", $sql );
		self::assertStringContainsString( 'id <> 7', $sql );
		self::assertStringContainsString( "scheduled_at < '2026-03-10 10:55:00'", $sql );
		self::assertStringContainsString( "planned_end_at > '2026-03-10 07:00:00'", $sql, 'Окно строго [начало, конец): встык кабинет свободен.' );
	}

	public function test_is_room_busy_true_when_overlap_counted(): void {
		$this->wpdb->queueVar( 1 );

		self::assertTrue( $this->repo->isRoomBusy( 2, '2026-03-10 07:00:00', '2026-03-10 10:55:00' ) );
	}

	public function test_list_future_open_by_room_filters_open_and_future(): void {
		$this->wpdb->queueResults( array( $this->row() ) );

		$sessions = $this->repo->listFutureOpenByRoom( 2, '2026-03-01 07:00:00' );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'room_id = 2', $sql );
		self::assertStringContainsString( "status = 'open'", $sql );
		self::assertStringContainsString( "scheduled_at > '2026-03-01 07:00:00'", $sql );
		self::assertSame( 7, $sessions[0]->id );
	}

	public function test_set_capacity_does_not_change_version(): void {
		$this->repo->setCapacity( 7, 20 );

		$sql = $this->wpdb->lastQuery();
		self::assertStringContainsString( 'capacity = 20', $sql );
		self::assertStringNotContainsString( 'version', $sql );
	}

	public function test_cancel_open_by_event_touches_only_open_sessions_and_bumps_version(): void {
		$this->wpdb->queueQuery( 3 );

		$count = $this->repo->cancelOpenByEvent( 3, 'Нет места', '2026-03-01 07:00:00' );

		$sql = $this->wpdb->lastQuery();
		self::assertSame( 3, $count );
		self::assertStringContainsString( "status = 'cancelled'", $sql );
		self::assertStringContainsString( "cancel_reason = 'Нет места'", $sql );
		self::assertStringContainsString( 'version = version + 1', $sql );
		self::assertStringContainsString( "event_id = 3 AND status = 'open'", $sql );
	}

	public function test_update_requires_expected_version(): void {
		$this->wpdb->queueQuery( 0 );

		self::assertFalse( $this->repo->update( 7, array( 'room_id' => 4 ), 5 ) );
		self::assertStringContainsString( 'WHERE id = 7 AND version = 5', $this->wpdb->lastQuery() );
	}

	public function test_mark_first_started_writes_only_once(): void {
		$this->repo->markFirstStarted( 7, '2026-03-10 07:05:00' );

		self::assertStringContainsString( 'first_started_at IS NULL', $this->wpdb->lastQuery() );
	}

	public function test_list_in_window_by_room_skips_cancelled_and_excluded_and_returns_title_and_start(): void {
		$this->wpdb->queueResults( array( array( 'event_title' => 'Пробный ОГЭ', 'session_start' => '2026-03-10 11:00:00' ) ) );

		$rows = $this->repo->listInWindowByRoom( 2, '2026-03-10 10:55:00', '2026-03-10 11:30:00', 7 );

		$sql = $this->wpdb->lastQuery();
		self::assertSame( array( array( 'title' => 'Пробный ОГЭ', 'start' => '2026-03-10 11:00:00' ) ), $rows );
		self::assertStringContainsString( "s.status <> 'cancelled'", $sql );
		self::assertStringContainsString( 's.id <> 7', $sql );
		self::assertStringContainsString( "s.scheduled_at < '2026-03-10 11:30:00'", $sql );
		self::assertStringContainsString( "s.planned_end_at > '2026-03-10 10:55:00'", $sql );
	}

	public function test_list_for_teacher_excludes_cancelled_and_draft_events_and_limits_to_own_unless_global(): void {
		$this->wpdb->queueResults( array() );
		$this->repo->listForTeacherBetween( 10, false, '2026-03-01 00:00:00', '2026-04-01 00:00:00' );
		self::assertStringContainsString( 's.responsible_user_id = 10', $this->wpdb->lastQuery() );
		self::assertStringContainsString( "e.status = 'published'", $this->wpdb->lastQuery() );
		self::assertStringContainsString( "s.status <> 'cancelled'", $this->wpdb->lastQuery() );

		$this->wpdb->queueResults( array() );
		$this->repo->listForTeacherBetween( 10, true, '2026-03-01 00:00:00', '2026-04-01 00:00:00' );
		self::assertStringNotContainsString( 'responsible_user_id', $this->wpdb->lastQuery() );
	}
}
