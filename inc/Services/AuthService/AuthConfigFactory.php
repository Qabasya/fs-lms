<?php

namespace Inc\Services\AuthService;

use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;

/**
 * Class AuthConfigFactory
 *
 * Фабрика для создания конфигурации Hybridauth.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Формирование конфигурации** — создание массива настроек для библиотеки Hybridauth.
 * 2. **Построение callback PageRoutes** — генерация PageRoutes для обратного вызова после авторизации.
 * 3. **Конфигурация провайдеров** — сбор настроек всех поддерживаемых соцсетей из репозитория.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение настроек провайдеров SettingsRepository.
 * Используется в AuthController и AuthService для инициализации Hybridauth.
 */
readonly class AuthConfigFactory {

	public function __construct(
		private SettingsRepository $settings_repo
	) {}

	/**
	 * Создаёт полную конфигурацию для Hybridauth.
	 *
	 * @param AuthProvider|null $provider Провайдер (для построения callback PageRoutes)
	 *
	 * @return array
	 */
	public function make( ?AuthProvider $provider = null ): array {
		return array(
			'callback'   => $this->buildCallback( $provider ),
			'debug_mode' => $this->isDebugMode(),
			'debug_file' => $this->getDebugFile(),
			'providers'  => $this->buildProvidersConfig(),
		);
	}

	/**
	 * Строит callback PageRoutes для провайдера.
	 *
	 * @param AuthProvider|null $provider Провайдер
	 *
	 * @return string
	 */
	private function buildCallback( ?AuthProvider $provider ): string {
		// home_url() — возвращает PageRoutes главной страницы сайта
		$base = home_url( '/lms-auth/callback' );

		if ( ! $provider ) {
			return $base;
		}

		// add_query_arg() — добавляет параметр к PageRoutes
		return add_query_arg(
			'provider',
			strtolower( $provider->value ),
			$base
		);
	}

	/**
	 * Проверяет, включён ли режим отладки.
	 *
	 * @return bool
	 */
	private function isDebugMode(): bool {
		// WP_DEBUG — константа WordPress для режима отладки
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	/**
	 * Возвращает путь к файлу лога Hybridauth.
	 *
	 * @return string
	 */
	private function getDebugFile(): string {
		// WP_CONTENT_DIR — константа с путём к папке wp-content
		return WP_CONTENT_DIR . '/hybridauth.log';
	}

	/**
	 * Строит конфигурацию для всех провайдеров.
	 *
	 * @return array
	 */
	private function buildProvidersConfig(): array {
		$settings = $this->settings_repo->readAll();
		$result   = array();

		foreach ( AuthProvider::cases() as $provider ) {
			$key = $provider->configKey();

			// hybridauthKey() — название провайдера в Hybridauth (Google, Vkontakte, GitHub)
			$result[ $provider->hybridauthKey() ] = $this->buildProvider( $key, $settings );
		}

		return $result;
	}

	/**
	 * Строит конфигурацию для одного провайдера.
	 *
	 * @param string $key      Ключ провайдера в настройках
	 * @param array  $settings Массив настроек из репозитория
	 *
	 * @return array
	 */
	private function buildProvider( string $key, array $settings ): array {
		return array(
			'enabled' => ! empty( $settings[ $key . '_enabled' ] ),
			'keys'    => array(
				'id'     => $settings[ $key . '_id' ] ?? '',
				'secret' => $settings[ $key . '_secret' ] ?? '',
			),
		);
	}
}
