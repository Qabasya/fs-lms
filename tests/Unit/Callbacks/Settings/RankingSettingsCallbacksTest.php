<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Settings;

use Inc\Callbacks\Settings\RankingSettingsCallbacks;
use Inc\Enums\Access\Capability;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Services\Settings\RankingSettingsService;
use PHPUnit\Framework\TestCase;

class RankingSettingsCallbacksTest extends TestCase {

	protected function tearDown(): void {
		fs_test_reset_ajax();
		parent::tearDown();
	}

	public function test_settings_save_and_invalid_data_json_error(): void {
		fs_test_reset_ajax();
		$before = $GLOBALS['_test_options'] ?? array();
		try {
			$repository = new PluginConfigRepository();
			$callback = new RankingSettingsCallbacks( new RankingSettingsService( $repository ) );
			$_POST = array( 'settings' => array( 'homework' => '0.25' ) );
			self::assertTrue( fs_test_capture_json( fn() => $callback->ajaxSaveRankingSettings() )->success );
			self::assertSame( 0.25, $repository->get()['ranking_homework'] );
			$_POST = array( 'settings' => array( 'homework' => 'bad' ) );
			self::assertFalse( fs_test_capture_json( fn() => $callback->ajaxSaveRankingSettings() )->success );
			self::assertSame( 0.25, $repository->get()['ranking_homework'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
		}
	}
	public function test_manage_lms_platform_permission_is_required(): void {
		fs_test_reset_ajax();
		$service = $this->createMock(RankingSettingsService::class);
		$service->expects(self::never())->method('save');
		$GLOBALS['_fs_test_can_callback'] = static fn(string $cap): bool => Capability::Admin->value === $cap;
		try {
			$reply=fs_test_capture_json(fn()=>(new RankingSettingsCallbacks($service))->ajaxSaveRankingSettings());
			self::assertFalse($reply->success);
		} finally {
			unset($GLOBALS['_fs_test_can_callback']);
		}
	}

}
