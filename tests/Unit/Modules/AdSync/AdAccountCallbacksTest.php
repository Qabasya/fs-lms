<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Callbacks\AdAccountCallbacks;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use PHPUnit\Framework\TestCase;

/** Кнопка «Создать учётку» в окне заявки: состояние и ручное создание доменной учётки. */
class AdAccountCallbacksTest extends TestCase {

	private $service;
	private $delivery;
	private AdAccountCallbacks $callbacks;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service   = $this->createMock( AdProvisioningService::class );
		$this->delivery  = $this->createMock( AdDeliveryService::class );
		$this->callbacks = new AdAccountCallbacks( $this->service, $this->delivery );
	}

	public function test_state_describes_the_button(): void {
		$this->service->method( 'accountStateForApplication' )->with( 5 )->willReturn( 'creatable' );
		$_POST = array( 'application_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxState() );

		self::assertTrue( $r->success );
		self::assertSame( 'creatable', $r->payload['state'] );
		self::assertSame( 'Создать учётку', $r->payload['label'] );
	}

	public function test_provision_queues_the_job_and_sends_it_at_once(): void {
		$this->service->method( 'accountStateForApplication' )->willReturnOnConsecutiveCalls( 'creatable', 'done' );
		$this->service->expects( self::once() )->method( 'enqueueProvision' )->with( 5 );
		$this->delivery->expects( self::once() )->method( 'deliverPending' );
		$_POST = array( 'application_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxProvision() );

		self::assertTrue( $r->success );
		self::assertSame( 'done', $r->payload['state'] );
		self::assertSame( 'Учётка создана', $r->payload['label'] );
	}

	public function test_provision_is_refused_when_account_already_exists(): void {
		$this->service->method( 'accountStateForApplication' )->willReturn( 'done' );
		$this->service->expects( self::never() )->method( 'enqueueProvision' );
		$this->delivery->expects( self::never() )->method( 'deliverPending' );
		$_POST = array( 'application_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxProvision() )->success );
	}

	public function test_provision_denied_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->service->expects( self::never() )->method( 'enqueueProvision' );
		$_POST = array( 'application_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->callbacks->ajaxProvision() )->success );
	}
}
