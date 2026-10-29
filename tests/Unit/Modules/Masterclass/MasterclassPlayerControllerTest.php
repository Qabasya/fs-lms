<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Callbacks\MasterclassPlayerCallbacks;
use Inc\Modules\Masterclass\Controllers\MasterclassPlayerController;
use Inc\Modules\Masterclass\DTO\MasterclassViewDTO;
use Inc\Modules\Masterclass\Enums\MasterclassAccountState;
use Inc\Modules\Masterclass\Services\MasterclassAccessGuard;
use Inc\Modules\Masterclass\Services\MasterclassLoginTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Швы Ш2 на настоящих шаблонах: временному ученику — баннер, «Связаться», «Выйти», окно заморозки; всем остальным — ничего.
 */
class MasterclassPlayerControllerTest extends TestCase {

	private MasterclassAccessGuard&MockObject $guard;
	private MasterclassLoginTracker&MockObject $tracker;
	private MasterclassPlayerController $controller;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_logged_in']    = true;
		$GLOBALS['_fs_test_user_id']   = 105;
		$GLOBALS['_fs_test_page_slug'] = 'lesson';
		unset( $GLOBALS['_fs_test_scripts'], $GLOBALS['_fs_test_filter_returns'] );

		$this->guard      = $this->createMock( MasterclassAccessGuard::class );
		$this->tracker    = $this->createMock( MasterclassLoginTracker::class );
		$this->controller = new MasterclassPlayerController( $this->guard, $this->tracker, $this->createStub( MasterclassPlayerCallbacks::class ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_logged_in'], $GLOBALS['_fs_test_user_id'], $GLOBALS['_fs_test_page_slug'], $GLOBALS['_fs_test_scripts'], $GLOBALS['_fs_test_filter_returns'] );
		parent::tearDown();
	}

	private function view( array $over = array() ): MasterclassViewDTO {
		$a = array_merge(
			array(
				'state'     => MasterclassAccountState::Active,
				'frozen'    => false,
				'banner'    => true,
				'text'      => 'Свяжитесь с администратором',
				'phone'     => '+7 (900) 123-45-67',
				'messenger' => 'https://t.me/center',
			),
			$over
		);

		return new MasterclassViewDTO( $a['state'], $a['frozen'], $a['banner'], $a['text'], $a['phone'], $a['messenger'], 'http://example.com/apply/', 'http://example.com/out' );
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();

		return (string) ob_get_clean();
	}

	// ── чужим ничего ────────────────────────────────────────────────────

	public function test_for_everyone_else_the_seams_change_nothing(): void {
		$this->guard->method( 'viewFor' )->willReturn( null );

		self::assertSame( 'http://back', $this->controller->filterBackUrl( 'http://back', 7 ) );
		self::assertSame( '<p>стандартный замок</p>', $this->controller->filterLockHtml( '<p>стандартный замок</p>', 7 ) );
		self::assertSame( '', $this->capture( fn () => $this->controller->renderActions( 7 ) ) );
		self::assertSame( '', $this->capture( fn () => $this->controller->renderBanner( 7 ) ) );
		self::assertSame( '', $this->capture( fn () => $this->controller->renderContact( 7 ) ) );
	}

	// ── временному ученику ──────────────────────────────────────────────

	public function test_back_button_is_removed(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view() );

		self::assertSame( '', $this->controller->filterBackUrl( 'http://back', 105 ) );
	}

	public function test_header_has_contact_and_logout(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view() );

		$html = $this->capture( fn () => $this->controller->renderActions( 105 ) );

		self::assertStringContainsString( 'data-mk-open', $html );
		self::assertStringContainsString( 'href="http://example.com/out"', $html );
	}

	public function test_banner_is_drawn_when_the_view_asks_for_it(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view() );

		$html = $this->capture( fn () => $this->controller->renderBanner( 105 ) );

		self::assertStringContainsString( 's-banner', $html );
		self::assertStringContainsString( 'Свяжитесь с администратором', $html );
		self::assertStringContainsString( 'data-mk-open', $html );
		self::assertStringContainsString( 'http://example.com/apply/', $html );
	}

	public function test_no_banner_when_the_view_hides_it(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view( array( 'banner' => false ) ) );

		self::assertSame( '', $this->capture( fn () => $this->controller->renderBanner( 105 ) ) );
	}

	public function test_applied_banner_has_no_application_link(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view( array( 'state' => MasterclassAccountState::Applied ) ) );

		$html = $this->capture( fn () => $this->controller->renderBanner( 105 ) );

		self::assertStringContainsString( 'Заявка принята', $html );
		self::assertStringNotContainsString( 'http://example.com/apply/', $html );
	}

	public function test_contact_window_is_hidden_and_carries_the_ajax_data(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view() );

		$html = $this->capture( fn () => $this->controller->renderContact( 105 ) );

		self::assertStringContainsString( 'data-mk-contact', $html );
		self::assertMatchesRegularExpression( '/data-mk-contact[^>]*\shidden>/', $html );
		self::assertStringContainsString( 'data-action="fs_lms_masterclass_contact"', $html );
		self::assertStringContainsString( 'data-nonce="', $html );
		self::assertStringContainsString( 'href="tel:+79001234567"', $html );
		self::assertStringContainsString( 'https://t.me/center', $html );
	}

	public function test_contact_window_skips_missing_phone_and_messenger(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view( array( 'phone' => '', 'messenger' => '' ) ) );

		$html = $this->capture( fn () => $this->controller->renderContact( 105 ) );

		self::assertStringNotContainsString( 'tel:', $html );
		self::assertStringNotContainsString( 'data-mk-kind="messenger"', $html );
		self::assertStringContainsString( 'data-mk-kind="apply"', $html );
	}

	// ── окно заморозки ──────────────────────────────────────────────────

	public function test_frozen_lock_replaces_the_standard_text(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view( array( 'frozen' => true, 'banner' => false ) ) );

		$html = (string) $this->controller->filterLockHtml( '', 105 );

		self::assertStringContainsString( 'Доступ к мастер-классу закрыт', $html );
		self::assertStringContainsString( 'data-mk-open', $html );
		self::assertStringContainsString( 'http://example.com/apply/', $html );
		self::assertStringContainsString( 'http://example.com/out', $html );
	}

	public function test_frozen_lock_for_a_submitted_application_says_so(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view( array( 'frozen' => true, 'state' => MasterclassAccountState::Applied ) ) );

		$html = (string) $this->controller->filterLockHtml( '', 105 );

		self::assertStringContainsString( 'Заявка принята', $html );
		self::assertStringNotContainsString( 'http://example.com/apply/', $html );
	}

	public function test_lock_is_untouched_while_not_frozen(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view() );

		self::assertSame( '<p>скоро начнётся</p>', $this->controller->filterLockHtml( '<p>скоро начнётся</p>', 105 ) );
	}

	public function test_no_template_carries_inline_styles_or_svg(): void {
		$files = glob( dirname( __DIR__, 4 ) . '/inc/Modules/Masterclass/templates/player-*.php' );
		self::assertCount( 4, $files );

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'style=', $source, basename( $file ) );
			self::assertStringNotContainsString( '<svg', $source, basename( $file ) );
		}
	}

	// ── открытие плеера и скрипт ────────────────────────────────────────

	public function test_opening_the_player_goes_to_the_tracker(): void {
		$this->tracker->expects( self::once() )->method( 'onPlayerOpen' )->with( 105 );

		$this->controller->onPlayerRoute();
	}

	public function test_other_pages_and_guests_do_not_reach_the_tracker(): void {
		$this->tracker->expects( self::never() )->method( 'onPlayerOpen' );

		$GLOBALS['_fs_test_page_slug'] = 'profile';
		$this->controller->onPlayerRoute();

		$GLOBALS['_fs_test_page_slug'] = 'lesson';
		$GLOBALS['_test_logged_in']    = false;
		$this->controller->onPlayerRoute();
	}

	public function test_a_tracker_failure_never_breaks_the_page(): void {
		$this->tracker->method( 'onPlayerOpen' )->willThrowException( new \RuntimeException( 'db down' ) );

		$this->controller->onPlayerRoute();

		$this->addToAssertionCount( 1 );
	}

	public function test_script_loads_only_on_the_player_route_for_an_account(): void {
		$this->guard->method( 'viewFor' )->willReturn( $this->view() );

		$this->controller->enqueueAssets();
		self::assertArrayNotHasKey( 'fs-lms-masterclass-player', $GLOBALS['_fs_test_scripts'] ?? array(), 'не маршрут плеера' );

		$GLOBALS['_fs_test_filter_returns']['fs_lms_is_player_route'] = true;
		$this->controller->enqueueAssets();
		self::assertArrayHasKey( 'fs-lms-masterclass-player', $GLOBALS['_fs_test_scripts'] );
	}

	public function test_script_is_not_loaded_for_a_regular_user_on_the_player(): void {
		$this->guard->method( 'viewFor' )->willReturn( null );
		$GLOBALS['_fs_test_filter_returns']['fs_lms_is_player_route'] = true;

		$this->controller->enqueueAssets();

		self::assertArrayNotHasKey( 'fs-lms-masterclass-player', $GLOBALS['_fs_test_scripts'] ?? array() );
	}
}
