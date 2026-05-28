<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Contracts\CaptchaProviderInterface;

/**
 * Class CaptchaService
 *
 * Фасад над провайдером капчи. Единственная точка входа для верификации капчи в callbacks.
 *
 * @package Inc\Services
 *
 * ### Архитектурная роль:
 *
 * Изолирует callbacks и контроллеры от конкретного провайдера. Смена провайдера
 * (hCaptcha → Yandex SmartCaptcha и т.д.) производится в DI-контейнере без
 * изменения кода, который вызывает validate().
 *
 * ### Использование в callback:
 *
 * ```php
 * if ( ! $this->captchaService->validate( $token, $ip ) ) {
 *     $this->error( 'Капча не пройдена.' );
 * }
 * ```
 *
 * ### Admin notice:
 *
 * Контроллер проверяет isConfigured() и добавляет предупреждение если капча
 * не настроена. Сам сервис не взаимодействует с WP admin.
 */
readonly class CaptchaService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param CaptchaProviderInterface $provider Провайдер капчи (из DI-контейнера)
	 */
	public function __construct(
		private CaptchaProviderInterface $provider,
	) {}

	/**
	 * Верифицирует токен капчи.
	 *
	 * Делегирует в провайдер. NullCaptchaProvider всегда возвращает true.
	 *
	 * @param string $token    Токен с фронта
	 * @param string $remoteIp IP-адрес клиента
	 *
	 * @return bool
	 */
	public function validate( string $token, string $remoteIp ): bool {
		return $this->provider->validate( $token, $remoteIp );
	}

	/**
	 * Возвращает публичный site key для рендера виджета капчи.
	 *
	 * @return string Пустая строка если провайдер не сконфигурирован
	 */
	public function getSiteKey(): string {
		return $this->provider->getSiteKey();
	}

	/**
	 * Проверяет, настроен ли реальный провайдер капчи.
	 *
	 * @return bool false для NullCaptchaProvider
	 */
	public function isConfigured(): bool {
		return $this->provider->isConfigured();
	}
}