<?php

declare( strict_types=1 );

namespace Inc\Services\CaptchaProviders;

use Inc\Contracts\CaptchaProviderInterface;

/**
 * Class NullCaptchaProvider
 *
 * Провайдер-заглушка: используется когда ни один реальный провайдер не сконфигурирован.
 *
 * @package Inc\Services\CaptchaProviders
 *
 * ### Поведение:
 *
 * - validate() всегда возвращает true — капча не блокирует запросы.
 * - isConfigured() возвращает false — контроллер показывает admin notice.
 * - getSiteKey() возвращает '' — фронт не рендерит виджет.
 *
 * ### Когда заменяется:
 *
 * Заменяется на реальный провайдер (HCaptchaProvider, SmartCaptchaProvider и др.)
 * в DI-контейнере после того как site key и secret key добавлены в настройки FS LMS.
 */
class NullCaptchaProvider implements CaptchaProviderInterface {

	public function validate( string $token, string $remoteIp ): bool {
		return true;
	}

	public function getSiteKey(): string {
		return '';
	}

	public function isConfigured(): bool {
		return false;
	}
}