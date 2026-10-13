<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Callbacks\AdSyncStatusCallbacks;
use Inc\Modules\AdSync\Controllers\AdSyncController;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdStatusTokenService;
use Inc\Services\Shared\PluginConfig;
use PHPUnit\Framework\TestCase;

/**
 * Учётка в домене ставится в очередь при подаче заявки только из доверенной сети
 * (`FS_LMS_TRUSTED_IPS`): с любого другого адреса её создаёт сотрудник кнопкой в окне заявки.
 */
class AdSyncControllerTest extends TestCase {

	private string $remoteAddr = '';

	protected function setUp(): void {
		parent::setUp();
		$this->remoteAddr = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
	}

	protected function tearDown(): void {
		$_SERVER['REMOTE_ADDR'] = $this->remoteAddr;
		parent::tearDown();
	}

	public function test_application_from_trusted_network_gets_account_at_once(): void {
		$_SERVER['REMOTE_ADDR'] = '9.9.9.9';

		$service = $this->createMock( AdProvisioningService::class );
		$service->expects( self::once() )->method( 'enqueueProvision' )->with( 5 );

		$this->controller( $service )->onApplicationCreated( 5 );
	}

	public function test_application_from_other_address_waits_for_staff(): void {
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';

		$service = $this->createMock( AdProvisioningService::class );
		$service->expects( self::never() )->method( 'enqueueProvision' );

		$this->controller( $service )->onApplicationCreated( 5 );
	}

	private function controller( AdProvisioningService $service ): AdSyncController {
		$config = $this->createStub( PluginConfig::class );
		$config->method( 'isTrustedIp' )->willReturnCallback( static fn( string $ip ): bool => '9.9.9.9' === $ip );

		return new AdSyncController(
			$service,
			$this->createStub( AdDeliveryService::class ),
			$this->createStub( AdStatusTokenService::class ),
			$this->createStub( AdSyncStatusCallbacks::class ),
			$config,
		);
	}
}
