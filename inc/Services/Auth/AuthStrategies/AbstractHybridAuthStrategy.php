<?php

namespace Inc\Services\Auth\AuthStrategies;

use Hybridauth\Exception\InvalidArgumentException;
use Hybridauth\Exception\UnexpectedValueException;
use Hybridauth\Hybridauth;
use Inc\Contracts\AuthStrategyInterface;
use Inc\Services\Auth\AuthConfigFactory;
use Inc\Services\Auth\AuthService;

/**
 * Абстрактный класс AbstractHybridAuthStrategy
 *
 * Базовый класс для стратегий аутентификации через Hybridauth (социальные сети).
 *
 * @package Inc\Services\Auth\AuthStrategies
 *
 * ### Основные обязанности:
 *
 * 1. **Инициализация Hybridauth** — создание экземпляра библиотеки с конфигурацией провайдера.
 * 2. **Запуск процесса входа** — перенаправление на страницу авторизации провайдера.
 *
 * ### Архитектурная роль:
 *
 * Является абстрактным базовым классом для конкретных стратегий (Google, VK, GitHub).
 * Реализует общую логику работы с Hybridauth, делегируя получение провайдера
 * конкретным реализациям через метод getProvider().
 */
abstract class AbstractHybridAuthStrategy implements AuthStrategyInterface {

	/**
	 * @var Hybridauth|null Экземпляр Hybridauth для текущей стратегии
	 */
	protected ?Hybridauth $hybridauth = null;

	/**
	 * Конструктор стратегии.
	 *
	 * @param AuthConfigFactory $config_factory Фабрика конфигурации Hybridauth
	 * @param AuthService       $auth_service   Сервис аутентификации (для входа в WP)
	 */
	public function __construct(
		protected AuthConfigFactory $config_factory,
		protected AuthService $auth_service
	) {}

	/**
	 * Инициализирует экземпляр Hybridauth с конфигурацией провайдера.
	 *
	 * @throws InvalidArgumentException При некорректной конфигурации
	 *
	 * @return void
	 */
	protected function initHybrid(): void {
		if ( ! $this->hybridauth ) {
			// make() — создаёт конфигурационный массив для Hybridauth
			$this->hybridauth = new Hybridauth( $this->config_factory->make( $this->getProvider() ) );
		}
	}

	/**
	 * Запускает процесс входа через провайдера.
	 * Перенаправляет пользователя на страницу авторизации соцсети.
	 *
	 * @throws UnexpectedValueException    При ошибке в ответе провайдера
	 * @throws InvalidArgumentException    При некорректной конфигурации
	 *
	 * @return void
	 */
	public function login(): void {
		$this->initHybrid();
		// authenticate() — перенаправляет на страницу авторизации провайдера
		$this->hybridauth->authenticate( $this->getProvider()->hybridauthKey() );
	}
}
