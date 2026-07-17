<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Callbacks\VideoLibraryCallbacks;
use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Controllers\VideoLibraryController;
use Inc\Modules\VideoLibrary\Services\S3UrlSigner;
use PHPUnit\Framework\TestCase;

class VideoLibraryControllerTest extends TestCase {

	private S3UrlSigner $signer;
	private VideoLibraryController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->signer     = $this->createMock( S3UrlSigner::class );
		$this->controller = new VideoLibraryController(
			$this->createMock( VideoLibraryCallbacks::class ),
			$this->signer,
			$this->createMock( VideoLibraryConfig::class ),
		);
	}

	public function test_s3_pointer_is_presigned(): void {
		$this->signer->method( 'presign' )
			->with( 'test-bucket', 'videos/kege-1/rec.webm' )
			->willReturn( 'https://s3.example.com/test-bucket/videos/kege-1/rec.webm?X-Amz-Signature=abc' );

		$url = $this->controller->filterRecordingUrl( 's3://test-bucket/videos/kege-1/rec.webm' );

		self::assertStringStartsWith( 'https://s3.example.com/', (string) $url );
	}

	public function test_plain_http_url_passes_through(): void {
		$this->signer->expects( self::never() )->method( 'presign' );

		self::assertSame(
			'https://vk.com/video-1_2',
			$this->controller->filterRecordingUrl( 'https://vk.com/video-1_2' )
		);
	}

	public function test_null_passes_through(): void {
		self::assertNull( $this->controller->filterRecordingUrl( null ) );
	}

	public function test_malformed_s3_pointer_passes_through(): void {
		$this->signer->expects( self::never() )->method( 'presign' );

		self::assertSame( 's3://only-bucket', $this->controller->filterRecordingUrl( 's3://only-bucket' ) );
	}
}
