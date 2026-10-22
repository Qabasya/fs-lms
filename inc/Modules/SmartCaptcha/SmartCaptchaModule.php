<?php

declare( strict_types=1 );

namespace Inc\Modules\SmartCaptcha;

use Inc\Modules\SmartCaptcha\Providers\YandexSmartCaptchaProvider;
use Inc\Contracts\CaptchaProviderInterface;
use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\PageRoutes;
use Inc\Modules\SmartCaptcha\Config\SmartCaptchaConfig;
use Inc\Modules\SmartCaptcha\Controllers\SmartCaptchaSettingsController;

/**
 * Class SmartCaptchaModule
 *
 * Опциональный модуль — невидимая капча Yandex SmartCaptcha на форме заявки /lms/apply
 * и на форме входа /sign-in/.
 * Ядро о модуле не знает: провайдер подменяется через фильтр `fs_lms_captcha_provider`
 * (core `CaptchaProviderFactory` по умолчанию отдаёт NullCaptchaProvider), а site key
 * и внешний скрипт добавляются через `fs_lms_apply_vars` / `fs_lms_login_vars` /
 * `wp_enqueue_scripts`.
 *
 * Уровни выключения:
 *  1) тумблер на странице «Статистика» (опция `fs_lms_smart_captcha.enabled`);
 *  2) константа `FS_LMS_SMART_CAPTCHA` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/SmartCaptcha/` + строки в `Init::getServices()`.
 *
 * @package Inc\Modules\SmartCaptcha
 */
class SmartCaptchaModule implements ServiceInterface {

	/** Хендл внешнего скрипта Yandex SmartCaptcha. */
	private const SCRIPT_HANDLE = 'fs-lms-smartcaptcha';

	public function __construct(
		private readonly SmartCaptchaSettingsController $settings,
		private readonly SmartCaptchaConfig             $config,
		// Модуль ВЛАДЕЕТ реализацией: ядро получает её только через фильтр
		// `fs_lms_captcha_provider` и знает лишь CaptchaProviderInterface
		// (см. CaptchaProviderFactory). Биндинг интерфейса в core-контейнере
		// заставил бы ядро знать класс модуля — этого мы избегаем.
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

		// Кладём публичный ключ в переменные форм заявки и входа (ядро прогоняет их через фильтр).
		add_filter( 'fs_lms_apply_vars', array( $this, 'addCaptchaKey' ) );
		add_filter( 'fs_lms_login_vars', array( $this, 'addCaptchaKey' ) );

		// Грузим внешний скрипт Yandex SmartCaptcha на страницах заявки и входа (после core-бандла).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueCaptchaScript' ), 20 );
		add_filter( 'script_loader_tag', array( $this, 'asyncCaptchaScript' ), 10, 2 );
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
		// Только на страницах заявки и входа и только если задан клиентский ключ.
		// Скрипт зависит от core-бандла: тот первым ставит window.__fsSmartCaptchaReady.
		$onFormPage = PageRoutes::Apply->isCurrent() || PageRoutes::SignIn->isCurrent();

		if ( ! $onFormPage || '' === $this->provider->getSiteKey() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=__fsSmartCaptchaReady',
			array( 'fs-lms-frontend-script' ),
			null,
			true
		);
	}

	/**
	 * Скрипт Яндекса не должен блокировать разбор страницы.
	 *
	 * Обычный скрипт в футере держит `DOMContentLoaded`, пока не скачается. Из сетей, где Яндекс
	 * не отвечает, а «висит» (VPN), это ~10 секунд без обработчика формы: ученик успевал нажать
	 * «Войти», и запрос уходил без токена и без причины («Капча: форма не прислала ни токен, ни
	 * причину»). С `async` страница и обработчик не ждут Яндекс, а недоступность капчи
	 * обрабатывает сама форма (см. captcha.js: `not_loaded` через 6 с → смягчённое правило).
	 *
	 * Атрибут ставится явно, а не через `strategy` ядра: у скрипта есть зависимость от
	 * блокирующего бандла, и для такого случая ядро не гарантирует именно `async` (`defer`
	 * `DOMContentLoaded` держит так же). Порядок выполнения сохраняется: тег идёт после бандла,
	 * где задан `__fsSmartCaptchaReady`, а async-скрипт выполняется не раньше, чем парсер дошёл до тега.
	 *
	 * @param string $tag    Готовый тег `<script>`
	 * @param string $handle Хендл скрипта
	 */
	public function asyncCaptchaScript( string $tag, string $handle ): string {
		if ( self::SCRIPT_HANDLE !== $handle || str_contains( $tag, ' async' ) ) {
			return $tag;
		}

		return (string) preg_replace( '/<script\b/', '<script async', $tag, 1 );
	}
}
