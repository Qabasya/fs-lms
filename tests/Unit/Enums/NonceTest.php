<?php

declare( strict_types=1 );

namespace Unit\Enums;

use Inc\Enums\Wp\Nonce;
use PHPUnit\Framework\TestCase;

/**
 * Провал nonce в AJAX — 403 с кодом E-SESSION и свежим токеном для повтора.
 */
class NonceTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_nonce_ok'], $GLOBALS['_fs_test_doing_ajax'], $_SERVER[ Nonce::RETRY_HEADER ] );
	}

	public function test_valid_nonce_passes(): void {
		Nonce::AllTasks->verify();

		$this->addToAssertionCount( 1 );
	}

	public function test_stale_nonce_returns_fresh_one_for_retry(): void {
		$GLOBALS['_fs_test_nonce_ok'] = false;

		$response = fs_test_capture_json( static fn() => Nonce::AllTasks->verify( 'nonce' ) );

		self::assertFalse( $response->success );
		self::assertSame( 'E-SESSION', $response->payload['code'] );
		self::assertSame(
			array( 'field' => 'nonce', 'value' => Nonce::AllTasks->create() ),
			$response->payload['nonce']
		);
	}

	public function test_non_ajax_request_dies(): void {
		$GLOBALS['_fs_test_nonce_ok']   = false;
		$GLOBALS['_fs_test_doing_ajax'] = false;

		$this->expectException( \FsTestWpDie::class );

		Nonce::AllTasks->verify();
	}

	public function test_retry_header_is_detected(): void {
		self::assertFalse( Nonce::isRetry() );

		$_SERVER[ Nonce::RETRY_HEADER ] = '1';

		self::assertTrue( Nonce::isRetry() );
	}
}
