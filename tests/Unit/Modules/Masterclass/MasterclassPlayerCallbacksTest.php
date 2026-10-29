<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Callbacks\MasterclassPlayerCallbacks;
use Inc\Modules\Masterclass\Enums\MasterclassContactKind;
use Inc\Modules\Masterclass\Services\MasterclassConversionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AJAX окна «Связаться»: nonce плеера, залогиненный пользователь, известный вид клика; ответ успеха — всегда один и тот же.
 */
class MasterclassPlayerCallbacksTest extends TestCase {

	private MasterclassConversionService&MockObject $conversion;
	private MasterclassPlayerCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 105;
		$_POST = $_GET = array();

		$this->conversion = $this->createMock( MasterclassConversionService::class );
		$this->cb         = new MasterclassPlayerCallbacks( $this->conversion );
	}

	protected function tearDown(): void {
		$_POST = $_GET = array();
		parent::tearDown();
	}

	/** @param array<string, string> $post */
	private function call( array $post ): \FsTestJsonResponse {
		$_POST = $post;

		return fs_test_capture_json( fn() => $this->cb->ajaxContact() );
	}

	public function test_a_click_is_logged_for_the_current_user(): void {
		$this->conversion->expects( self::once() )->method( 'logContact' )->with( 105, MasterclassContactKind::Phone );

		self::assertTrue( $this->call( array( 'kind' => 'phone' ) )->success );
	}

	public function test_a_stale_nonce_is_refused_before_anything_happens(): void {
		$GLOBALS['_fs_test_nonce_ok'] = false;
		$this->conversion->expects( self::never() )->method( 'logContact' );

		self::assertFalse( $this->call( array( 'kind' => 'phone' ) )->success );

		unset( $GLOBALS['_fs_test_nonce_ok'] );
	}

	public function test_a_guest_gets_an_error(): void {
		$GLOBALS['_fs_test_user_id'] = 0;
		$this->conversion->expects( self::never() )->method( 'logContact' );

		self::assertFalse( $this->call( array( 'kind' => 'open' ) )->success );
	}

	public function test_an_unknown_kind_is_an_error(): void {
		$this->conversion->expects( self::never() )->method( 'logContact' );

		self::assertFalse( $this->call( array( 'kind' => 'hack' ) )->success );
		self::assertFalse( $this->call( array() )->success );
	}

	public function test_the_answer_does_not_reveal_whether_the_user_has_an_account(): void {
		// Сервис молча игнорирует чужой аккаунт: ответ тот же, что и для аккаунта мастер-класса.
		$this->conversion->method( 'logContact' );

		$response = $this->call( array( 'kind' => 'open' ) );

		self::assertTrue( $response->success );
		self::assertEmpty( $response->payload );
	}
}
