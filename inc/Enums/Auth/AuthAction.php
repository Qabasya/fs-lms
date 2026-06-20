<?php

declare( strict_types=1 );

namespace Inc\Enums\Auth;

enum AuthAction: string {
	case Login         = 'login';
	case LoginFailed   = 'login_failed';
	case OtpSent       = 'otp_sent';
	case OtpVerified   = 'otp_verified';
	case PasswordReset = 'password_reset';

	public function label(): string {
		return match ( $this ) {
			self::Login         => 'Вход',
			self::LoginFailed   => 'Неудача входа',
			self::OtpSent       => 'OTP отправлен',
			self::OtpVerified   => 'OTP подтверждён',
			self::PasswordReset => 'Сброс пароля',
		};
	}
}
