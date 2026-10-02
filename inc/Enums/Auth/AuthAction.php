<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

enum AuthAction: string {
	case Login         = 'login';
	case LoginFailed   = 'login_failed';
	case OtpSent       = 'otp_sent';
	case OtpVerified   = 'otp_verified';
	case PasswordReset = 'password_reset';
	// Форма заявки (/lms/apply): что происходило до отправки кода (события шлёт браузер)
	case ApplyStarted        = 'apply_started';
	case ApplyInvalid        = 'apply_invalid';
	case ApplyCaptchaShown   = 'apply_captcha_shown';
	case ApplyCaptchaFailed  = 'apply_captcha_failed';
	case ApplySubmitFailed   = 'apply_submit_failed';
	case ApplyLeft           = 'apply_left';
	// Капча не дошла до браузера, форма пропущена по смягчённому правилу
	case CaptchaFallback     = 'captcha_fallback';

	public function label(): string {
		return match ( $this ) {
			self::Login         => 'Вход',
			self::LoginFailed   => 'Неудача входа',
			self::OtpSent       => 'OTP отправлен',
			self::OtpVerified   => 'OTP подтверждён',
			self::PasswordReset => 'Сброс пароля',
			self::ApplyStarted       => 'Заявка: начал заполнять',
			self::ApplyInvalid       => 'Заявка: форма не прошла проверку',
			self::ApplyCaptchaShown  => 'Заявка: капча показала задание',
			self::ApplyCaptchaFailed => 'Заявка: капча не пройдена',
			self::ApplySubmitFailed  => 'Заявка: отправка не удалась',
			self::ApplyLeft          => 'Заявка: ушёл, не отправив',
			self::CaptchaFallback    => 'Пропуск без капчи',
		};
	}
}
