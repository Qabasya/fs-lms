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
		unset( $GLOBALS['_fs_test_user_id'], $GLOBALS['_test_transients'] );
		parent::tearDown();
	}

	public function test_cache_is_separate_for_users_and_objects_but_every_issue_is_logged(): void {
		$GLOBALS['_test_transients'] = array();
		$GLOBALS['_fs_test_user_id'] = 10;
		$signer = $this->createMock( S3UrlSigner::class );
		$signer->expects( self::exactly( 3 ) )->method( 'presign' )->willReturn( 'signed-a', 'signed-b', 'signed-c' );
		$config = $this->createStub( VideoLibraryConfig::class );
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
}
