<?php

declare( strict_types=1 );

namespace Inc\Contracts;

/**
 * Interface CaptchaProviderInterface
 *
 * Контракт для провайдеров капчи (hCaptcha, Yandex SmartCaptcha и др.).
 *
 * @package Inc\Contracts
 *
 * ### Как добавить нового провайдера:
 *
 * 1. Создать класс в `inc/Services/CaptchaProviders/`, реализующий этот интерфейс.
 * 2. Зарегистрировать его в DI-контейнере вместо NullCaptchaProvider.
 * 3. CaptchaService и все callbacks остаются без изменений.
 */
interface CaptchaProviderInterface {

	/**
	 * Верифицирует токен капчи через API провайдера.
	 *
	 * @param string $token    Токен, полученный на фронте после решения капчи
	 * @param string $remoteIp IP-адрес клиента для передачи провайдеру
	 *
	 * @return bool true если токен валиден
	 */
	public function validate( string $token, string $remoteIp ): bool;

	/**
	 * Возвращает публичный site key для рендера виджета капчи на фронте.
	 *
	 * @return string Пустая строка если ключ не настроен
	 */
	public function getSiteKey(): string;

	/**
	 * Проверяет, настроен ли провайдер (site key + secret key заполнены).
	 *
	 * Используется в контроллере для показа admin notice,
	 * если капча не сконфигурирована.
	 *
	 * @return bool
	 */
	public function isConfigured(): bool;
}