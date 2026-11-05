<?php

declare(strict_types=1);

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Repositories\VideoInventoryRepository;
use Inc\Modules\VideoLibrary\Services\VideoInventoryExportService;
use PHPUnit\Framework\TestCase;

class VideoInventoryExportServiceTest extends TestCase {
	public function test_exports_private_snapshot_and_refuses_to_overwrite(): void {
		$path = sys_get_temp_dir() . '/video-inventory-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$data = array( 'schema' => 1, 'recordings' => array( array( 'id' => 10, 's3_key' => 'videos/test' ) ), 'lessons' => array() );
		$repo = $this->createMock( VideoInventoryRepository::class );
		$repo->method( 'snapshot' )->willReturn( $data );
		$service = new VideoInventoryExportService( $repo );
		try {
			$service->export( $path );
			self::assertSame( $data, json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ) );
			// Windows reports synthetic POSIX mode bits; access there is controlled by the directory ACL.
			if ( 'Windows' !== PHP_OS_FAMILY ) {
				self::assertSame( 0600, fileperms( $path ) & 0777 );
			}
			$before = file_get_contents( $path );
			try {
				$service->export( $path );
				self::fail( 'Existing output must not be overwritten.' );
			} catch ( \RuntimeException $e ) {
				self::assertStringContainsString( 'не перезаписывается', $e->getMessage() );
			}
			self::assertSame( $before, file_get_contents( $path ) );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function test_database_failure_does_not_create_output(): void {
		$path = sys_get_temp_dir() . '/video-inventory-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$repo = $this->createMock( VideoInventoryRepository::class );
		$repo->method( 'snapshot' )->willThrowException( new \RuntimeException( 'Database failed' ) );
		try {
			( new VideoInventoryExportService( $repo ) )->export( $path );
			self::fail( 'Database failure must be reported.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'Database failed', $e->getMessage() );
		}
		self::assertFileDoesNotExist( $path );
	}
}
