<?php

declare( strict_types=1 );

namespace Inc\Services\AuthService;

use Inc\Contracts\AuthStrategyInterface;
use Inc\Enums\Auth\AuthProvider;
use Inc\Services\AuthService\AuthStrategies\GithubAuthStrategy;
use Inc\Services\AuthService\AuthStrategies\GoogleAuthStrategy;
use Inc\Services\AuthService\AuthStrategies\VkAuthStrategy;

/**
 * Class AuthStrategyRegistry
 *
 * Реестр стратегий аутентификации для различных провайдеров.
 *
 * @package Inc\Services\AuthService
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация стратегий** — хранение объектов стратегий для каждого провайдера.
 * 2. **Получение стратегии** — возврат стратегии по провайдеру.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Registry для стратегий аутентификации.
 * Используется в AuthController для получения нужной стратегии
 * на основе определённого провайдера.
 */
class AuthStrategyRegistry {

	/**
	 * Массив стратегий, ключ — значение enum AuthProvider.
	 *
	 * @var array<string, AuthStrategyInterface>
	 */
	private array $strategies;

	/**
	 * Конструктор.
	 *
	 * @param GoogleAuthStrategy $google_strategy Стратегия для Google
	 * @param VkAuthStrategy     $vk_strategy     Стратегия для ВКонтакте
	 * @param GithubAuthStrategy $github_strategy Стратегия для GitHub
	 */
	public function __construct(
		GoogleAuthStrategy $google_strategy,
		VkAuthStrategy $vk_strategy,
		GithubAuthStrategy $github_strategy,
	) {
		// Регистрация стратегий с привязкой к значению enum
		$this->strategies = array(
			AuthProvider::Google->value    => $google_strategy,
			AuthProvider::Vkontakte->value => $vk_strategy,
			AuthProvider::Github->value    => $github_strategy,
		);
	}

	/**
	 * Возвращает стратегию для указанного провайдера.
	 *
	 * @param AuthProvider|null $provider Провайдер (или null)
	 *
	 * @return AuthStrategyInterface|null
	 */
	public function get( ?AuthProvider $provider ): ?AuthStrategyInterface {
		if ( ! $provider ) {
			return null;
		}
		return $this->strategies[ $provider->value ] ?? null;
	}
}