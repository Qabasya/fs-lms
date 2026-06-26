<?php

declare( strict_types=1 );

namespace Inc\Modules\SmartCaptcha;

use Inc\Contracts\CaptchaProviderInterface;
use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\PageRoutes;
use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;
use Inc\Modules\SmartCaptcha\Controllers\SmartCaptchaSettingsController;
use Inc\Modules\SmartCaptcha\Providers\YandexSmartCaptchaProvider;

/**
 * Class SmartCaptchaModule
 *
 * Опциональный модуль — капча Yandex SmartCaptcha на форме /lms/apply.
 * Ядро о модуле не знает: провайдер подменяется через фильтр `fs_lms_captcha_provider`
 * (core `CaptchaProviderFactory` по умолчанию отдаёт NullCaptchaProvider), а site key
 * и внешний скрипт добавляются через `fs_lms_apply_vars` / `wp_enqueue_scripts`.
 *
 * Уровни выключения:
 *  1) тумблер на странице «Статистика» (опция `fs_lms_smart_captcha.enabled`);
 *  2) константа `FS_LMS_SMART_CAPTCHA` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/SmartCaptcha/` + строки в `Init::getServices()`.
 *
 * @package Inc\Modules\SmartCaptcha
 */
class SmartCaptchaModule implements ServiceInterface {

	public function __construct(
		private readonly SmartCaptchaSettingsController $settings,
		private readonly SmartCaptchaConfig             $config,
		private readonly YandexSmartCaptchaProvider     $provider,
	) {}

	public function register(): void {
		// Разовый перенос ключей из легаси core-конфига (back-compat существующих установок).
		$this->config->maybeMigrateFromCore();

		// Admin-настройки (секция + dashboard-тоггл + сохранение) — всегда: чтобы можно было включить.
		$this->settings->register();

		if ( ! $this->config->isEnabled() ) {
			return;
		}

		// Подменяем core-провайдер капчи на Yandex (ядро резолвит провайдер через фильтр в фабрике).
		add_filter( 'fs_lms_captcha_provider', array( $this, 'provideProvider' ) );

		// Кладём публичный ключ в переменные формы /lms/apply (ядро прогоняет их через фильтр).
		add_filter( 'fs_lms_apply_vars', array( $this, 'addCaptchaKey' ) );

		// Грузим внешний скрипт Yandex SmartCaptcha на странице заявки (после core-бандла).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueCaptchaScript' ), 20 );
	}

	public function provideProvider( CaptchaProviderInterface $default ): CaptchaProviderInterface {
		return $this->provider;
	}

	/**
	 * @param array<string, mixed> $vars
	 * @return array<string, mixed>
	 */
	public function addCaptchaKey( array $vars ): array {
		$vars['captcha_key'] = $this->provider->getSiteKey();
		return $vars;
	}

	public function enqueueCaptchaScript(): void {
		// Только на странице заявки и только если задан клиентский ключ.
		// Скрипт зависит от core-бандла: тот первым ставит window.__fsSmartCaptchaReady.
		if ( ! PageRoutes::Apply->isCurrent() || '' === $this->provider->getSiteKey() ) {
			return;
		}

		wp_enqueue_script(
			'fs-lms-smartcaptcha',
			'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=__fsSmartCaptchaReady',
			array( 'fs-lms-frontend-script' ),
			null,
			true
		);
	}
}
