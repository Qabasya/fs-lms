<?php

declare( strict_types=1 );

namespace Unit\Repositories;

use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

class GroupLessonScheduleStrictTest extends TestCase {
	public function test_following_group_topics_are_placed_after_the_tenth_pinned_topic(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$rows = array();
		foreach ( range( 1, 9 ) as $n ) {
			$rows[] = array( 'id' => $n, 'group_id' => 5, 'position' => $n, 'status' => 'held', 'scheduled_at' => sprintf( '2026-10-%02d 10:00:00', $n ) );
		}
		$rows[] = array( 'id' => 10, 'group_id' => 5, 'position' => 10, 'is_pinned' => 1, 'scheduled_at' => '2026-10-15 10:00:00' );
		$rows[] = array( 'id' => 11, 'group_id' => 5, 'position' => 11 );
		$rows[] = array( 'id' => 12, 'group_id' => 5, 'position' => 12 );
		$db->method( 'get_results' )->willReturn( $rows );
		$assigned = array();
		$db->method( 'update' )->willReturnCallback( static function ( $table, $data, $where ) use ( &$assigned ): int {
			$assigned[ $where['id'] ] = $data['scheduled_at'];
			return 1;
		} );
		$slots = array();
		foreach ( range( 1, 18 ) as $day ) {
			$slots[] = array( 'scheduled_at' => sprintf( '2026-10-%02d 10:00:00', $day ), 'ends_at' => null );
		}
		( new GroupLessonRepository( $db ) )->applySlots( 5, $slots );
		self::assertSame( array( 11 => '2026-10-16 10:00:00', 12 => '2026-10-17 10:00:00' ), $assigned );
	}

	public function test_next_group_lesson_follows_manually_pinned_lesson(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$db->method( 'get_results' )->willReturn( array(
			array( 'id' => 10, 'group_id' => 5, 'position' => 9, 'scheduled_at' => '2026-10-10 10:00:00', 'is_pinned' => 1 ),
			array( 'id' => 11, 'group_id' => 5, 'position' => 10 ),
		) );
		$db->expects( self::once() )->method( 'update' )->with(
			self::anything(), self::callback( static fn( array $data ): bool => '2026-10-11 10:00:00' === $data['scheduled_at'] ), array( 'id' => 11 )
		)->willReturn( 1 );
		$slots = array();
		foreach ( array( 1, 2, 10, 11 ) as $day ) {
			$slots[] = array( 'scheduled_at' => sprintf( '2026-10-%02d 10:00:00', $day ), 'ends_at' => null );
		}
		( new GroupLessonRepository( $db ) )->applySlots( 5, $slots );
	}

	public function test_next_group_lesson_follows_the_actual_date_of_a_held_lesson(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$db->method( 'get_results' )->willReturn( array(
			array( 'id' => 10, 'group_id' => 5, 'position' => 9, 'scheduled_at' => '2026-10-10 10:00:00', 'status' => 'held' ),
			array( 'id' => 11, 'group_id' => 5, 'position' => 10 ),
		) );
		$db->expects( self::once() )->method( 'update' )->with(
			self::anything(), self::callback( static fn( array $data ): bool => '2026-10-11 10:00:00' === $data['scheduled_at'] ), array( 'id' => 11 )
		)->willReturn( 1 );
		$slots = array();
		foreach ( array( 1, 2, 10, 11 ) as $day ) {
			$slots[] = array( 'scheduled_at' => sprintf( '2026-10-%02d 10:00:00', $day ), 'ends_at' => null );
		}
		( new GroupLessonRepository( $db ) )->applySlots( 5, $slots );
	}

	public function test_unschedule_db_error_throws_in_strict_mode(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturn( 'UPDATE' );
		$db->method( 'query' )->willReturn( false );
		$this->expectException( \RuntimeException::class );
		( new GroupLessonRepository( $db ) )->unscheduleAll( 5, true );
	}

	public function test_bulk_move_db_error_throws_in_strict_mode(): void {
		$db = $this->createMock( \wpdb::class );
		$db->last_error = '';
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$db->method( 'get_results' )->willReturn( array( array( 'id' => 42, 'group_id' => 5, 'position' => 0 ) ) );
		$db->method( 'update' )->willReturn( false );
		$this->expectException( \RuntimeException::class );
		( new GroupLessonRepository( $db ) )->applySlots( 5, array( array( 'scheduled_at' => '2026-12-01 10:00:00', 'ends_at' => null ) ), true );
	}
}
