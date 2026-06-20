<?php

declare( strict_types=1 );

namespace Inc\Enums\Email;

enum EmailTemplateType: string {
	case OtpCode                 = 'otp_code';
	case PasswordSetup           = 'password_setup';
	case ApplicationConfirmation = 'application_confirmation';
	case ApplicationReady        = 'application_ready';
	case Rejection               = 'rejection';
	case NewRepresentative       = 'new_representative';
	case WelcomeWithCredentials  = 'welcome_with_credentials';

	public function label(): string {
		return match ( $this ) {
			self::OtpCode                 => 'OTP-код',
			self::PasswordSetup           => 'Установка пароля',
			self::ApplicationConfirmation => 'Подтверждение заявки',
			self::ApplicationReady        => 'Заявка готова к рассмотрению',
			self::Rejection               => 'Отказ',
			self::NewRepresentative       => 'Новый представитель',
			self::WelcomeWithCredentials  => 'Приветствие с данными для входа',
		};
	}
}
