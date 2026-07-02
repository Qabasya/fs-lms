<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Profile;

use Inc\Callbacks\Profile\DashboardCallbacks;
use Inc\Services\Profile\DashboardService;
use PHPUnit\Framework\TestCase;

class DashboardCallbacksTest extends TestCase {

	private DashboardService&\PHPUnit\Framework\MockObject\MockObject $service;
	private DashboardCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service = $this->createMock( DashboardService::class );
		$this->cb      = new DashboardCallbacks( $this->service );
	}

	public function test_returns_dashboard_payload(): void {
		$payload = array( 'stats' => array( 'lessons_today' => 3 ), 'today' => array(), 'groups' => array() );
		$this->service->expects( $this->once() )->method( 'build' )->willReturn( $payload );
		$_POST = array();

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetProfileDashboard() );

		self::assertTrue( $r->success );
		self::assertSame( 3, $r->payload['stats']['lessons_today'] );
	}
}
