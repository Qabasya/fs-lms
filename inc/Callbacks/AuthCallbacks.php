<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;
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

	/**
	 * Рендерит тестовую страницу с кнопками входа через соцсети.
	 * Вызывается шорткодом [lms_auth_test].
	 *
	 * @return string HTML-контент страницы
	 */
	public function renderAuthTestPage(): string {
		$providers = array();

		// Сбор активных провайдеров (которые включены в настройках)
		foreach ( AuthProvider::cases() as $provider ) {
			// isProviderEnabled() — проверяет настройку {provider}_enabled
			if ( ! $this->settings_repo->isProviderEnabled( $provider->value ) ) {
				continue;
			}

			$providers[] = array(
				// home_url() — возвращает URL главной страницы сайта
				'url'   => home_url( '/lms-auth/' . $provider->configKey() ),
				'id'    => $provider->configKey(),
				'label' => $provider->label(),
			);
		}

		// Информация о текущем пользователе (для отображения статуса)
		$current_user = is_user_logged_in() ? wp_get_current_user() : null;

		// wp_logout_url() — генерирует URL для выхода из системы
		// get_permalink() — возвращает URL текущей страницы (возврат после выхода)
		$logout_url = wp_logout_url( get_permalink() );

		// Буферизация вывода для возврата строки (шорткод должен возвращать, а не выводить)
		ob_start();
		$this->render(
			'frontend/auth-test',
			array(
				'providers'    => $providers,
				'current_user' => $current_user,
				'logout_url'   => $logout_url,
			)
		);
		return (string) ob_get_clean();
	}
}
