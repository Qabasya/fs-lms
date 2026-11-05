<?php

declare(strict_types=1);

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Repositories\VideoInventoryRepository;
use PHPUnit\Framework\TestCase;

class VideoInventoryRepositoryTest extends TestCase {
	public function test_reset_dry_run_only_counts_links_and_registry(): void {
		$db = $this->createMock( \wpdb::class );
		$queries = array();
		$db->method( 'prepare' )->willReturnCallback( static function ( string $sql, ...$args ) use ( &$queries ): string {
			$queries[] = array( $sql, $args );
			return $sql;
		} );
		$db->expects( self::never() )->method( 'query' );
		$db->expects( self::exactly( 2 ) )->method( 'get_var' )->willReturnOnConsecutiveCalls( '3', '2' );
		self::assertSame( array( 'lessons' => 3, 'recordings' => 2 ), ( new VideoInventoryRepository( $db ) )->clearRecordings( true ) );
	}

	public function test_reset_clears_all_links_and_preserves_skip_flag(): void {
		$db = $this->createMock( \wpdb::class );
		$prepared = array();
		$db->method( 'prepare' )->willReturnCallback( static function ( string $sql, ...$args ) use ( &$prepared ): string {
			$prepared[] = array( $sql, $args );
			return $sql;
		} );
		$db->expects( self::exactly( 2 ) )->method( 'query' )->willReturnOnConsecutiveCalls( 4, 3 );
		self::assertSame( array( 'lessons' => 4, 'recordings' => 3 ), ( new VideoInventoryRepository( $db ) )->clearRecordings( false ) );
		self::assertStringContainsString( 'recording_url = NULL, recording_link = NULL', $prepared[0][0] );
		self::assertStringContainsString( 'group_lesson_id = NULL', $prepared[1][0] );
		self::assertStringNotContainsString( 'recording_skipped', $prepared[0][0] );
	}
	public function test_snapshot_reads_registry_and_includes_unregistered_lesson_pointers(): void {
		$db = $this->createMock( \wpdb::class );
		$db->expects( self::never() )->method( 'insert' );
		$db->expects( self::never() )->method( 'update' );
		$queries = array();
		$db->method( 'prepare' )->willReturnCallback( static function ( string $sql, ...$args ) use ( &$queries ): string {
			$queries[] = $sql;
			return $sql;
		} );
		$db->expects( self::exactly( 2 ) )->method( 'get_results' )->willReturnOnConsecutiveCalls(
			array( array( 'id' => '10', 's3_key' => 'videos/a' ) ),
			array( array( 'id' => '55', 'recording_url' => 's3://manual/videos/b' ) )
		);
		$result = ( new VideoInventoryRepository( $db ) )->snapshot();
		self::assertSame( 's3://manual/videos/b', $result['lessons'][0]['recording_url'] );
		self::assertStringNotContainsString( 'payload', $queries[0] );
		self::assertStringNotContainsString( 'secret', implode( '\n', $queries ) );
	}

	public function test_query_error_is_not_an_empty_success(): void {
		$db = $this->createMock( \wpdb::class );
		$db->method( 'prepare' )->willReturn( 'SELECT inventory' );
		$db->expects( self::once() )->method( 'get_results' )->willReturnCallback( static function () use ( $db ): array {
			$db->last_error = 'missing table';
			return array();
		} );
		$this->expectException( \RuntimeException::class );
		( new VideoInventoryRepository( $db ) )->snapshot();
	}
}
