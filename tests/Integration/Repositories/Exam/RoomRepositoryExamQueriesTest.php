<?php

declare( strict_types=1 );

namespace Integration\Repositories\Exam;

use FakeWpdb;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use PHPUnit\Framework\TestCase;

/** Кабинеты: блокировка строки перед назначением и занятия в окне позднего старта. */
class RoomRepositoryExamQueriesTest extends TestCase {

	private FakeWpdb $wpdb;
	private RoomRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->repo = new RoomRepository( $this->wpdb );
	}

	public function test_lock_for_update_locks_only_the_room_row(): void {
		$this->repo->lockForUpdate( 2 );

		self::assertStringContainsString( 'SELECT id FROM', $this->wpdb->lastQuery() );
		self::assertStringContainsString( 'WHERE id = 2 FOR UPDATE', $this->wpdb->lastQuery() );
	}

	public function test_lock_for_update_turns_database_error_into_exception(): void {
		$this->wpdb->last_error = 'Lock wait timeout exceeded';

		$this->expectException( \RuntimeException::class );

		$this->repo->lockForUpdate( 2 );
	}

	public function test_lessons_in_window_use_the_same_overlap_rule_as_is_busy(): void {
		$this->wpdb->queueResults( array( array( 'title' => 'Занятие 10А', 'lesson_start' => '2026-03-10 14:00:00' ) ) );

		$lessons = $this->repo->listLessonsInWindow( 2, '2026-03-10 13:55:00', '2026-03-10 14:30:00' );

		$sql = $this->wpdb->lastQuery();
		self::assertSame( array( array( 'title' => 'Занятие 10А', 'start' => '2026-03-10 14:00:00' ) ), $lessons );
		self::assertStringContainsString( 'COALESCE(gl.room_id, g.room_id) = 2', $sql );
		self::assertStringContainsString( "gl.scheduled_at < '2026-03-10 14:30:00'", $sql );
		self::assertStringContainsString( "> '2026-03-10 13:55:00'", $sql );
	}
}
