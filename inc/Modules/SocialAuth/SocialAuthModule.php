<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\SocialAuth\Config\SocialAuthConfig;
use Inc\Modules\SocialAuth\Controllers\SocialAuthController;
use Inc\Modules\SocialAuth\Controllers\SocialAuthPageController;
use Inc\Modules\SocialAuth\Controllers\SocialAuthSettingsController;

/**
 * Bootstrap изолируемого модуля SocialAuth (OAuth через социальные сети).
 * Единственная точка входа модуля; регистрируется одной строкой в `Init::getServices()`.
 * Ядро о внутренностях модуля не знает — связь через WP-хуки и kernel-сервисы.
 *
 * Уровни выключения:
 *  1) константа FS_LMS_SOCIAL_AUTH=false в wp-config.php (жёсткий оффлайн);
 *  2) опция fs_lms_social_auth['enabled'] = false (тумблер на «Статистике»);
 *  3) удаление каталога inc/Modules/SocialAuth/ + строки SocialAuthModule::class в Init.
 */
class SocialAuthModule implements ServiceInterface {

	public function __construct(
		private readonly SocialAuthSettingsController $settings,
		private readonly SocialAuthController         $runtime,
		private readonly SocialAuthPageController     $page,
		private readonly SocialAuthConfig             $config,
	) {}

	public function register(): void {
		// Разовый перенос флага из легаси core-конфига в свою опцию (back-compat).
		$this->config->maybeMigrateFromCore();

		// Настройки (вкладка + register_setting) — всегда: чтобы можно было настроить провайдеров.
		$this->settings->register();

		if ( ! $this->config->isEnabled() ) {
			return;
		}

		// OAuth-маршруты, страница входа и аватар/admin-bar фильтры.
		$this->runtime->register();
		$this->page->register();
	}
}
