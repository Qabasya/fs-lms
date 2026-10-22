<?php

declare(strict_types=1);

namespace Unit\Modules\SmartCaptcha;

use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;
use Inc\Modules\SmartCaptcha\Controllers\SmartCaptchaSettingsController;
use Inc\Modules\SmartCaptcha\Providers\YandexSmartCaptchaProvider;
use Inc\Modules\SmartCaptcha\SmartCaptchaModule;
use Inc\Services\CaptchaProviders\NullCaptchaProvider;
use PHPUnit\Framework\TestCase;

class SmartCaptchaModuleTest extends TestCase {

	private function makeModule( string $siteKey = 'site' ): array {
		$config = $this->createStub( SmartCaptchaConfig::class );
		$config->method( 'siteKey' )->willReturn( $siteKey );
		$config->method( 'serverKey' )->willReturn( 'server' );

		$provider = new YandexSmartCaptchaProvider( $config );
		$module   = new SmartCaptchaModule(
			$this->createStub( SmartCaptchaSettingsController::class ),
			$config,
			$provider,
		);

		return array( $module, $provider );
	}

	// Регресс: без импорта интерфейса тип параметра резолвился в пространство модуля → TypeError.
	public function test_provide_provider_accepts_core_null_provider(): void {
		[ $module, $provider ] = $this->makeModule();

		self::assertSame( $provider, $module->provideProvider( new NullCaptchaProvider() ) );
	}

	public function test_add_captcha_key_puts_site_key_into_vars(): void {
		[ $module ] = $this->makeModule( 'my-site-key' );

		$vars = $module->addCaptchaKey( array( 'ajax_url' => 'x' ) );

		self::assertSame( 'my-site-key', $vars['captcha_key'] );
		self::assertSame( 'x', $vars['ajax_url'] );
	}

	// Регресс: блокирующий скрипт Яндекса держал DOMContentLoaded, и форма входа уходила без обработчика.
	public function test_captcha_script_tag_becomes_async(): void {
		[ $module ] = $this->makeModule();
		$tag        = '<script src="https://smartcaptcha.yandexcloud.net/captcha.js" id="fs-lms-smartcaptcha-js"></script>';

		self::assertSame(
			'<script async src="https://smartcaptcha.yandexcloud.net/captcha.js" id="fs-lms-smartcaptcha-js"></script>',
			$module->asyncCaptchaScript( $tag, 'fs-lms-smartcaptcha' )
		);
	}

	public function test_async_is_not_duplicated(): void {
		[ $module ] = $this->makeModule();
		$tag        = '<script async src="https://smartcaptcha.yandexcloud.net/captcha.js"></script>';

		self::assertSame( $tag, $module->asyncCaptchaScript( $tag, 'fs-lms-smartcaptcha' ) );
	}

	public function test_other_scripts_are_left_blocking(): void {
		[ $module ] = $this->makeModule();
		$tag        = '<script src="https://example.test/frontend.min.js" id="fs-lms-frontend-script-js"></script>';

		self::assertSame( $tag, $module->asyncCaptchaScript( $tag, 'fs-lms-frontend-script' ) );
	}
}
