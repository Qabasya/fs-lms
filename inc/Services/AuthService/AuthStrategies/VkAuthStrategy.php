<?php

namespace Inc\Services\AuthService\AuthStrategies;

use Inc\DTO\Person\UserDTO;
use Inc\Enums\AuthProvider;
use Inc\Shared\PluginLogger;

/**
 * Class VkAuthStrategy
 *
 * Стратегия аутентификации через ВКонтакте (VK).
 *
 * @package Inc\Services\AuthService\AuthStrategies
 *
 * ### Основные обязанности:
 *
 * 1. **Определение провайдера** — возвращает enum AuthProvider::Vkontakte.
 * 2. **Аутентификация через VK** — получение профиля пользователя через Hybridauth.
 *
 * ### Архитектурная роль:
 *
 * Конкретная реализация стратегии для ВКонтакте.
 * Наследует AbstractHybridAuthStrategy, который содержит общую логику
 * работы с Hybridauth. Передаёт полученный профиль в AuthService
 * для обработки (поиск или создание пользователя, вход в WordPress).
 */
class VkAuthStrategy extends AbstractHybridAuthStrategy {

	/**
	 * Возвращает провайдера аутентификации (ВКонтакте).
	 *
	 * @return AuthProvider
	 */
	public function getProvider(): AuthProvider {
		return AuthProvider::Vkontakte;
	}

	/**
	 * Выполняет аутентификацию через ВКонтакте и возвращает DTO пользователя.
	 *
	 * @return UserDTO|null
	 */
	public function authenticate(): ?UserDTO {
		try {
			$this->initHybrid();

			// authenticate() — получает адаптер после возврата пользователя со страницы VK
			$adapter = $this->hybridauth->authenticate( $this->getProvider()->hybridauthKey() );
			// getUserProfile() — получает профиль пользователя (email, имя, avatar)
			$profile = $adapter->getUserProfile();
			// disconnect() — закрывает соединение с провайдером
			$adapter->disconnect();

			// Делегирование обработки профиля сервису аутентификации
			return $this->auth_service->processUserFromSocialProfile( $this->getProvider(), $profile );
		} catch ( \Exception $e ) {
			PluginLogger::exception( 'VkAuthStrategy', $e );
			return null;
		}
	}
}
