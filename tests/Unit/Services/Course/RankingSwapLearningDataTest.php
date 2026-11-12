<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use PHPUnit\Framework\TestCase;

class RankingSwapLearningDataTest extends TestCase {
	public function test_swap_keeps_extras_at_each_date_and_moves_topic_data(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'get_var' )->willReturn( '0' );
		$db->method( 'prepare' )->willReturnCallback( static fn( string $sql, ...$args ): string => $sql . ' | ' . implode( ',', array_map( 'strval', $args ) ) );
		$queries = array();
		$db->method( 'query' )->willReturnCallback( static function ( string $sql ) use ( &$queries ): int { $queries[] = $sql; return 1; } );
		( new GroupLessonRepository( $db ) )->swapLearningDataForTopics( 10, 20, array( 7 ), array( 8 ) );
		self::assertCount( 15, $queries );
		self::assertStringContainsString( 'work_id NOT IN (7)', $queries[0] );
		self::assertStringContainsString( 'work_id NOT IN (8)', $queries[1] );
		self::assertStringContainsString( 'work:7', $queries[6] );
		self::assertStringContainsString( 'work:8', $queries[7] );
	}

	public function test_occupied_temporary_lesson_prevents_all_writes(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'get_var' )->willReturn( '1' );
		$db->method( 'prepare' )->willReturn( 'SELECT' );
		$db->expects( self::never() )->method( 'query' );
		$this->expectException( \RuntimeException::class );
		( new GroupLessonRepository( $db ) )->swapLearningDataForTopics( 10, 20, array(), array() );
	}

	public function test_sql_failure_aborts_swap(): void {
		$db = $this->createStub( \wpdb::class );
		$db->method( 'get_var' )->willReturn( '0' );
		$db->method( 'prepare' )->willReturn( 'SQL' );
		$db->method( 'query' )->willReturn( false );
		$this->expectException( \RuntimeException::class );
		( new GroupLessonRepository( $db ) )->swapLearningDataForTopics( 10, 20, array(), array() );
	}
}
