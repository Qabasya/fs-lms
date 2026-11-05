<?php

declare(strict_types=1);

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Repositories\VideoInventoryRepository;
use PHPUnit\Framework\TestCase;

class VideoInventoryRepositoryTest extends TestCase {
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
