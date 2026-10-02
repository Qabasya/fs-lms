<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

/**
 * Enum CaptchaOutcome
 *
 * Итог проверки капчи формы: пройдена, пропущена по смягчённому правилу
 * (капча не дошла до браузера — VPN/блокировщик) или отклонена.
 *
 * @package Inc\Enums\Auth
 */
enum CaptchaOutcome {
	case Passed;
	case Fallback;
	case Rejected;

	public function isAllowed(): bool {
		return self::Rejected !== $this;
	}
}
