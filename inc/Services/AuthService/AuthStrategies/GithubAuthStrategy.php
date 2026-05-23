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
 * 1. **Определение провайдера** — возвращает enum AuthProvider::GITHUB.
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
	 * Конструктор стратегии.
	 * Наследует родительский конструктор.
	 */
	public function __construct(
		// Параметры передаются через DI-контейнер в родительский конструктор
	) {
		parent::__construct( ...func_get_args() );
	}

	/**
	 * Возвращает провайдера аутентификации (GitHub).
	 *
	 * @return AuthProvider
	 */
	public function getProvider(): AuthProvider {
		return AuthProvider::GITHUB;
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
			// error_log() — записывает ошибку в лог PHP
			error_log( 'LMS GitHub Auth Error: ' . $e->getMessage() );
			return null;
		}
	}
}
