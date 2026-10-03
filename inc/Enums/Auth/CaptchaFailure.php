<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

/**
 * Enum CaptchaFailure
 *
 * Почему браузер не получил токен капчи. Значение кейса — то, что присылает JS
 * (`captcha_unavailable` в запросе формы заявки / входа, `captcha` в событии трекинга).
 *
 * Первые три — сбой доставки капчи (чаще всего VPN или блокировщик: сервис Яндекса
 * не отвечает или не показывает задание). Это не вина пользователя, поэтому форма
 * пропускает такой запрос по смягчённому правилу (см. {@see \Inc\Services\Captcha\CaptchaService::check()}).
 * Закрытое задание — осознанный отказ пользователя, смягчение на него не распространяется.
 *
 * @package Inc\Enums\Auth
 */
enum CaptchaFailure: string {
	case NotLoaded = 'not_loaded';
	case Timeout   = 'timeout';
	case Network   = 'network';
	case Dismissed = 'dismissed';

	/** Можно ли пропустить запрос без токена по смягчённому правилу. */
	public function allowsFallback(): bool {
		return self::Dismissed !== $this;
	}

	public function failReason(): LoginFailReason {
		return match ( $this ) {
			self::NotLoaded => LoginFailReason::CaptchaNotLoaded,
			self::Timeout   => LoginFailReason::CaptchaTimeout,
			self::Network   => LoginFailReason::CaptchaNetwork,
			self::Dismissed => LoginFailReason::CaptchaDismissed,
		};
	}
}
