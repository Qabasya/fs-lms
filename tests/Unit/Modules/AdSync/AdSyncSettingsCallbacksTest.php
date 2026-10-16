<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Callbacks\AdSyncSettingsCallbacks;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdExclusionService;
use Inc\Modules\AdSync\Services\AdFullSyncService;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use PHPUnit\Framework\TestCase;

class AdSyncSettingsCallbacksTest extends TestCase {
	private AdFullSyncService $sync;
	private AdSyncSettingsCallbacks $callback;

	protected function setUp(): void {
		fs_test_reset_ajax();
		$config = $this->createMock( AdSyncConfig::class );
		$config->method( 'isEnabled' )->willReturn( true );
		$this->sync = $this->createMock( AdFullSyncService::class );
		$this->callback = new AdSyncSettingsCallbacks( $config, $this->createMock( SubjectRepository::class ), $this->createMock( AdDeliveryService::class ), $this->createMock( AdOutboxRepository::class ), $this->createMock( AdExclusionService::class ), $this->sync );
	}

	protected function tearDown(): void { fs_test_reset_ajax(); }

	public function test_full_sync_requires_administrator_and_valid_nonce(): void {
		$this->sync->expects( self::never() )->method( 'start' );
		$_POST = array( 'phase' => 'start' );
		$GLOBALS['_fs_test_can'] = false;
		self::assertFalse( fs_test_capture_json( fn() => $this->callback->ajaxFullSync() )->success );
		$GLOBALS['_fs_test_can'] = true;
		$GLOBALS['_fs_test_nonce_ok'] = false;
		self::assertFalse( fs_test_capture_json( fn() => $this->callback->ajaxFullSync() )->success );
	}

	public function test_phases_return_progress_and_errors_in_the_ajax_response(): void {
		$_POST = array( 'phase' => 'start' );
		$this->sync->expects( self::once() )->method( 'start' )->willReturn( array( 'run_id' => 'run', 'total' => 4, 'processed' => 0, 'rows' => array() ) );
		$response = fs_test_capture_json( fn() => $this->callback->ajaxFullSync() );
		self::assertTrue( $response->success );
		self::assertSame( 4, $response->payload['total'] );
		$_POST = array( 'phase' => 'step', 'run_id' => 'run' );
		$this->sync->expects( self::once() )->method( 'step' )->with( 'run' )->willThrowException( new \RuntimeException( 'Сервер недоступен' ) );
		$response = fs_test_capture_json( fn() => $this->callback->ajaxFullSync() );
		self::assertFalse( $response->success );
		self::assertSame( 'Сервер недоступен', $response->payload );
	}
}
