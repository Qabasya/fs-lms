<?php

declare(strict_types=1);

namespace Unit\Services\Captcha;

use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;
use Inc\Modules\SmartCaptcha\Providers\YandexSmartCaptchaProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

class YandexSmartCaptchaProviderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_test_http_response'], $GLOBALS['_test_http_last'] );
	}

	private function makeProvider( string $siteKey, string $serverKey ): YandexSmartCaptchaProvider {
		$config = $this->createMock( SmartCaptchaConfig::class );
		$config->method( 'siteKey' )->willReturn( $siteKey );
		$config->method( 'serverKey' )->willReturn( $serverKey );
		return new YandexSmartCaptchaProvider( $config );
	}

	// ── Не настроена ────────────────────────────────────────────────────────────

	public function test_validate_passes_when_not_configured(): void {
		$provider = $this->makeProvider( '', '' );
		self::assertTrue( $provider->validate( '', '1.2.3.4' ) );
		self::assertArrayNotHasKey( '_test_http_last', $GLOBALS, 'HTTP-запрос не должен выполняться' );
	}

	public function test_validate_fails_on_empty_token_when_configured(): void {
		$provider = $this->makeProvider( 'site', 'server' );
		self::assertFalse( $provider->validate( '', '1.2.3.4' ) );
	}

	// ── Ответы API ────────────────────────────────────────────────────────────────

	public function test_validate_true_on_status_ok(): void {
		$GLOBALS['_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":"ok"}' );
		$provider                       = $this->makeProvider( 'site', 'server' );

		self::assertTrue( $provider->validate( 'tok', '1.2.3.4' ) );

		// Серверный ключ и токен ушли в запрос.
		self::assertSame( 'server', $GLOBALS['_test_http_last']['args']['body']['secret'] );
		self::assertSame( 'tok', $GLOBALS['_test_http_last']['args']['body']['token'] );
	}

	public function test_validate_false_on_status_failed(): void {
		$GLOBALS['_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"status":"failed"}' );
		$provider                       = $this->makeProvider( 'site', 'server' );

		self::assertFalse( $provider->validate( 'tok', '1.2.3.4' ) );
	}

	// ── Fail-open ─────────────────────────────────────────────────────────────────

	public function test_validate_fail_open_on_network_error(): void {
		$GLOBALS['_test_http_response'] = new WP_Error( 'timeout' );
		$provider                       = $this->makeProvider( 'site', 'server' );

		self::assertTrue( $provider->validate( 'tok', '1.2.3.4' ) );
	}

	public function test_validate_fail_open_on_non_200(): void {
		$GLOBALS['_test_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
		$provider                       = $this->makeProvider( 'site', 'server' );

		self::assertTrue( $provider->validate( 'tok', '1.2.3.4' ) );
	}

	// ── isConfigured / getSiteKey ───────────────────────────────────────────────────

	public function test_is_configured_requires_both_keys(): void {
		self::assertTrue( $this->makeProvider( 'site', 'server' )->isConfigured() );
		self::assertFalse( $this->makeProvider( 'site', '' )->isConfigured() );
		self::assertFalse( $this->makeProvider( '', 'server' )->isConfigured() );
	}

	public function test_get_site_key_returns_configured_key(): void {
		self::assertSame( 'my-site-key', $this->makeProvider( 'my-site-key', 'server' )->getSiteKey() );
	}
}
