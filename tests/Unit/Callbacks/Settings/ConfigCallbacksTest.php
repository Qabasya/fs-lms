<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Settings;

use Inc\Callbacks\Settings\ConfigCallbacks;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use PHPUnit\Framework\TestCase;

class ConfigCallbacksTest extends TestCase {
	public function test_exam_tab_save_preserves_general_configuration_and_vice_versa(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		fs_test_reset_ajax();
		try {
			$repo = new PluginConfigRepository();
			$repo->save( array( 'test_env' => true, 'otp_bypass_code' => 'keep', 'consultation_url' => 'https://example.com/consult', 'exam_product_9' => 77 ) );
			$callback = new ConfigCallbacks( $repo, $this->createMock( PersonDocumentsRepository::class ) );
			$_POST = array( 'exam_hold_minutes' => '35' );
			self::assertTrue( fs_test_capture_json( fn() => $callback->ajaxSaveConfig() )->success );
			self::assertSame( 35, $repo->get()['exam_hold_minutes'] );
			self::assertTrue( $repo->get()['test_env'] );
			self::assertSame( 'keep', $repo->get()['otp_bypass_code'] );
			self::assertSame( 'https://example.com/consult', $repo->get()['consultation_url'] );
			$_POST = array( 'test_env' => '0', 'consultation_url' => '' );
			fs_test_capture_json( fn() => $callback->ajaxSaveConfig() );
			self::assertSame( 77, $repo->get()['exam_product_9'] );
			self::assertSame( 35, $repo->get()['exam_hold_minutes'] );
		} finally {
			$GLOBALS['_test_options'] = $before;
			fs_test_reset_ajax();
		}
	}
}
