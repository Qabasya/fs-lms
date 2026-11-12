<?php

declare( strict_types=1 );

namespace Unit\Repositories;

use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

class GroupLessonScheduleStrictTest extends TestCase {
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
