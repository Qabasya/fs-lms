<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Callbacks\VideoLibrarySettingsCallbacks;
use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Controllers\VideoLibrarySettingsController;
use Inc\Modules\VideoLibrary\Services\RecordingAlertService;
use Inc\Modules\VideoLibrary\Services\VideoAccessLogPageService;
use PHPUnit\Framework\TestCase;

class VideoAccessLogPageTest extends TestCase {
	public function test_module_tab_is_lazy_and_disappears_when_disabled(): void {
		$config = $this->createMock( VideoLibraryConfig::class );
		$config->method( 'isEnabled' )->willReturnOnConsecutiveCalls( true, false );
		$page = $this->createMock( VideoAccessLogPageService::class );
		$page->expects( self::never() )->method( 'build' );
		$controller = new VideoLibrarySettingsController(
			$this->createStub( VideoLibrarySettingsCallbacks::class ), $config,
			$this->createStub( RecordingAlertService::class ), $page,
		);
		$tabs = $controller->registerLogTab( array() );
		self::assertSame( 'Доступ к видео', $tabs[ VideoAccessLogPageService::TAB ]['label'] );
		self::assertIsCallable( $tabs[ VideoAccessLogPageService::TAB ]['load'] );
		self::assertStringEndsWith( 'templates/access-log.php', $tabs[ VideoAccessLogPageService::TAB ]['path'] );
		self::assertSame( array(), $controller->registerLogTab( array() ) );
	}
}
