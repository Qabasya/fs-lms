<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Services\RecordingUrlService;
use Inc\Modules\VideoLibrary\Services\S3UrlSigner;
use Inc\Modules\VideoLibrary\Services\VideoAccessLogService;
use PHPUnit\Framework\TestCase;

class RecordingUrlServiceTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'], $GLOBALS['_test_transients'], $GLOBALS['_test_transient_ttls'] );
		parent::tearDown();
	}

	public function test_cache_is_separate_for_users_and_objects_but_every_issue_is_logged(): void {
		$GLOBALS['_test_transients'] = array();
		$GLOBALS['_fs_test_user_id'] = 10;
		$signer = $this->createMock( S3UrlSigner::class );
		$signer->expects( self::exactly( 3 ) )->method( 'presign' )->willReturn( 'signed-a', 'signed-b', 'signed-c' );
		$config = $this->createStub( VideoLibraryConfig::class );
		$config->method( 's3Ready' )->willReturn( true );
		$config->method( 'presignTtl' )->willReturn( 60 );
		$log = $this->createMock( VideoAccessLogService::class );
		$log->expects( self::exactly( 4 ) )->method( 'logPresign' );
		$service = new RecordingUrlService( $signer, $config, $log );
		self::assertSame( 'signed-a', $service->resolve( 's3://bucket/a', 42 ) );
		self::assertSame( 'signed-a', $service->resolve( 's3://bucket/a', 42 ) );
		$GLOBALS['_fs_test_user_id'] = 11;
		self::assertSame( 'signed-b', $service->resolve( 's3://bucket/a', 42 ) );
		self::assertSame( 'signed-c', $service->resolve( 's3://bucket/b', 43 ) );
	}

	public function test_missing_credentials_do_not_sign_log_or_serve_cached_links(): void {
		$GLOBALS['_fs_test_user_id'] = 10;
		$GLOBALS['_test_transients'] = array( 'fsvid_url_' . md5( '10|bucket|a' ) => 'old-signed' );
		$signer = $this->createMock( S3UrlSigner::class );
		$signer->expects( self::never() )->method( 'presign' );
		$log = $this->createMock( VideoAccessLogService::class );
		$log->expects( self::never() )->method( 'logPresign' );
		$config = $this->createStub( VideoLibraryConfig::class );
		$config->method( 's3Ready' )->willReturn( false );
		self::assertSame( 's3://bucket/a', ( new RecordingUrlService( $signer, $config, $log ) )->resolve( 's3://bucket/a' ) );
	}

	public function test_cache_expires_before_short_and_long_signatures(): void {
		foreach ( array( 1 => 1, 60 => 30, 21600 => 21300 ) as $ttl => $cacheTtl ) {
			$GLOBALS['_test_transients'] = array();
			$GLOBALS['_test_transient_ttls'] = array();
			$config = $this->createMock( VideoLibraryConfig::class );
			$config->method( 's3Ready' )->willReturn( true );
			$config->expects( self::once() )->method( 'presignTtl' )->willReturn( $ttl );
			$signer = $this->createMock( S3UrlSigner::class );
			$signer->expects( self::once() )->method( 'presign' )->with( 'bucket', 'a', $ttl )->willReturn( 'signed' );
			$service = new RecordingUrlService( $signer, $config, $this->createStub( VideoAccessLogService::class ) );
			self::assertSame( 'signed', $service->resolve( 's3://bucket/a' ) );
			self::assertSame( array( $cacheTtl ), array_values( $GLOBALS['_test_transient_ttls'] ) );
		}
	}
	public function test_storage_and_ttl_changes_invalidate_links_without_exposing_credentials(): void {
		$GLOBALS['_test_transients'] = array();
		$s3 = array( 'endpoint' => 'https://old.example', 'region' => 'ru-1', 'key' => 'old-access', 'secret' => 'old-secret' );
		$ttl = 60;
		$config = $this->createStub( VideoLibraryConfig::class );
		$config->method( 's3Ready' )->willReturn( true );
		$config->method( 's3' )->willReturnCallback( static function () use ( &$s3 ): array {
			return $s3;
		} );
		$config->method( 'presignTtl' )->willReturnCallback( static function () use ( &$ttl ): int {
			return $ttl;
		} );
		$signer = $this->createMock( S3UrlSigner::class );
		$signer->expects( self::exactly( 6 ) )->method( 'presign' )->willReturn( 'initial', 'endpoint', 'region', 'access', 'secret', 'ttl' );
		$service = new RecordingUrlService( $signer, $config, $this->createStub( VideoAccessLogService::class ) );
		self::assertSame( 'initial', $service->resolve( 's3://bucket/a' ) );
		self::assertSame( 'initial', $service->resolve( 's3://bucket/a' ) );
		foreach ( array( 'endpoint' => 'https://new.example', 'region' => 'new-region', 'key' => 'new-access', 'secret' => 'new-secret' ) as $field => $value ) {
			$s3[ $field ] = $value;
			$service->resolve( 's3://bucket/a' );
		}
		$ttl = 120;
		self::assertSame( 'ttl', $service->resolve( 's3://bucket/a' ) );
		self::assertSame( 'ttl', $service->resolve( 's3://bucket/a' ) );
		foreach ( array_keys( $GLOBALS['_test_transients'] ) as $key ) {
			self::assertStringNotContainsString( 'new-secret', $key );
			self::assertStringNotContainsString( 'new-access', $key );
		}
	}

}
