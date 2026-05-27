<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Inc\DTO\UserDTO;
use Inc\Enums\AuthProvider;

/**
 * Class GithubAuthStrategy
 *
 * Стратегия аутентификации через GitHub.
 *
 * @package Inc\Services\AuthService\AuthStrategies
 *
 * ### Основные обязанности:
 *
 * 1. **Определение провайдера** — возвращает enum AuthProvider::Github.
 * 2. **Аутентификация через GitHub** — получение профиля пользователя через Hybridauth.
 *
 * ### Архитектурная роль:
 *
 * Конкретная реализация стратегии для GitHub.
 * Наследует AbstractHybridAuthStrategy, который содержит общую логику
 * работы с Hybridauth. Передаёт полученный профиль в AuthService
 * для обработки (поиск или создание пользователя, вход в WordPress).
 */
class GithubAuthStrategy extends AbstractHybridAuthStrategy {

	/**
	 * Возвращает провайдера аутентификации (GitHub).
	 *
	 * @return AuthProvider
	 */
	public function getProvider(): AuthProvider {
		return AuthProvider::Github;
	}

	/**
	 * Выполняет аутентификацию через GitHub и возвращает DTO пользователя.
	 *
	 * @return UserDTO|null
	 */
	public function authenticate(): ?UserDTO {
		try {
			$this->initHybrid();

			// authenticate() — получает адаптер после возврата пользователя со страницы GitHub
			$adapter = $this->hybridauth->authenticate( $this->getProvider()->hybridauthKey() );
			// getUserProfile() — получает профиль пользователя (email, имя, avatar)
			$profile = $adapter->getUserProfile();
			// disconnect() — закрывает соединение с провайдером
			$adapter->disconnect();

			// Делегирование обработки профиля сервису аутентификации
			return $this->auth_service->processUserFromSocialProfile( $this->getProvider(), $profile );
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[FS LMS] GithubAuthStrategy: ' . $e->getMessage() . ' | Context: ' . wp_json_encode( array( 'file' => $e->getFile(), 'line' => $e->getLine() ), JSON_UNESCAPED_UNICODE ) );
			}
			return null;
		}
	}
}
