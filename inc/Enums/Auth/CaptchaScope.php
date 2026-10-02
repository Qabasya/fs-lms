<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

/**
 * Enum CaptchaScope
 *
 * Форма, на которой проверяется капча. Определяет лимит смягчённого пропуска
 * ({@see \Inc\Services\Security\RateLimitService::allowCaptchaFallback()}) и подпись в журнале.
 *
 * @package Inc\Enums\Auth
 */
enum CaptchaScope: string {
	case Apply = 'apply';
	case Login = 'login';

	public function label(): string {
		return match ( $this ) {
			self::Apply => 'форма заявки',
			self::Login => 'форма входа',
		};
	}
}
