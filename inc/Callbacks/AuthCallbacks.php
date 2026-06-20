<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Auth\AuthProvider;
use Inc\Repositories\OptionsRepositories\SettingsRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class AuthCallbacks
 *
 * Коллбеки для аутентификации (отображение страниц, шорткодов).
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Рендеринг тестовой страницы** — отображение страницы с кнопками входа через соцсети.
 * 2. **Сбор активных провайдеров** — фильтрация только включённых в настройках провайдеров.
 * 3. **Передача данных в шаблон** — подготовка данных для шаблона фронтенда.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение настроек провайдеров SettingsRepository,
 * а рендеринг — трейту TemplateRenderer. Используется в шорткоде 'lms_auth_test'.
 */
class AuthCallbacks extends BaseController {

	use TemplateRenderer;  // Трейт с методом render() для подключения шаблонов

	public function __construct( private readonly SettingsRepository $settings_repo ) {
		parent::__construct();
	}

	/**
	 * Возвращает список только включенных и настроенных социальных провайдеров.
	 * Используется как для тестовых, так и для боевых страниц авторизации.
	 *
	 * @return array<array{url: string, id: string, label: string}> Массив активных провайдеров
	 */
	public function getEnabledProviders(): array {
		$providers = array();

		foreach ( AuthProvider::cases() as $provider ) {
			// isProviderEnabled() — проверяет настройку {provider}_enabled
			if ( ! $this->settings_repo->isProviderEnabled( $provider->value ) ) {
				continue;
			}

			$providers[] = array(
				'url'   => home_url( '/lms-auth/' . $provider->configKey() ),
				'id'    => $provider->configKey(),
				'label' => $provider->label(),
			);
		}

		return $providers;
	}
}
