<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

/**
 * Enum LoginFailReason
 *
 * Причина неудачного входа для журнала аутентификации. Определяет
 * {@see \Inc\Services\Security\LoginDiagnosticsService}; пользователю не показывается —
 * форма входа по-прежнему не различает неверный логин и неверный пароль.
 *
 * @package Inc\Enums\Auth
 */
enum LoginFailReason: string {
	case UnknownLogin    = 'unknown_login';
	case LoginLayout     = 'login_layout';
	case WrongPassword   = 'wrong_password';
	case PasswordLayout  = 'password_layout';
	case CapsLock        = 'caps_lock';
	case FirstLetterCase = 'first_letter_case';
	case CaptchaMissing  = 'captcha_missing';
	case CaptchaRejected = 'captcha_rejected';
	case CaptchaNotLoaded = 'captcha_not_loaded';
	case CaptchaTimeout   = 'captcha_timeout';
	case CaptchaNetwork   = 'captcha_network';
	case CaptchaDismissed = 'captcha_dismissed';
	case Locked          = 'locked';
	case Other           = 'other';

	public function label(): string {
		return match ( $this ) {
			self::UnknownLogin    => 'Нет такого логина или email',
			self::LoginLayout     => 'Логин набран в русской раскладке',
			self::WrongPassword   => 'Неверный пароль',
			self::PasswordLayout  => 'Пароль верный, но набран в русской раскладке',
			self::CapsLock        => 'Пароль верный, но включён Caps Lock',
			self::FirstLetterCase => 'Пароль верный, кроме регистра первой буквы',
			self::CaptchaMissing  => 'Капча: токен не пришёл с формы',
			self::CaptchaRejected => 'Капча: токен отклонён сервисом',
			self::CaptchaNotLoaded => 'Капча: не загрузилась (возможно, VPN или блокировщик)',
			self::CaptchaTimeout   => 'Капча: не открылась вовремя (возможно, VPN)',
			self::CaptchaNetwork   => 'Капча: ошибка сети при загрузке (возможно, VPN)',
			self::CaptchaDismissed => 'Капча: задание закрыто, не решено',
			self::Locked          => 'Вход закрыт после неудачных попыток',
			self::Other           => 'Другая ошибка',
		};
	}
}
