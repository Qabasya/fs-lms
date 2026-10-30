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

	/** Дата и время экзамена по предмету из `datetime-local`; пустое поле снимает дату; остальные настройки не трогаются. */
	public function test_exam_dates_are_saved_per_subject_and_empty_value_clears_it(): void {
		$before = $GLOBALS['_test_options'] ?? array();
		fs_test_reset_ajax();
		try {
			$repo = new PluginConfigRepository();
			$repo->save( array( 'exam_hold_minutes' => 33 ) );
			$callback = new ConfigCallbacks( $repo, $this->createMock( PersonDocumentsRepository::class ) );

			$_POST = array( 'exam_date__inf_ege' => '2027-06-02T10:00', 'exam_date__inf_oge' => '2027-05-20T09:30' );
			self::assertTrue( fs_test_capture_json( fn() => $callback->ajaxSaveConfig() )->success );
			self::assertSame( array( 'inf_ege' => '2027-06-02 10:00', 'inf_oge' => '2027-05-20 09:30' ), $repo->get()['exam_dates'] );
			self::assertSame( 33, $repo->get()['exam_hold_minutes'] );

			$_POST = array( 'exam_date__inf_ege' => '', 'exam_date__inf_oge' => '2027-05-20T09:30' );
			fs_test_capture_json( fn() => $callback->ajaxSaveConfig() );
			self::assertSame( array( 'inf_oge' => '2027-05-20 09:30' ), $repo->get()['exam_dates'] );

			$_POST = array( 'exam_hold_minutes' => '40' );
			fs_test_capture_json( fn() => $callback->ajaxSaveConfig() );
			self::assertSame( array( 'inf_oge' => '2027-05-20 09:30' ), $repo->get()['exam_dates'], 'форма без дат даты не стирает' );
		} finally {
			$GLOBALS['_test_options'] = $before;
			fs_test_reset_ajax();
		}
	}
}
