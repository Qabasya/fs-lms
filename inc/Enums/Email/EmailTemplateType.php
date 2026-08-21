<?php

declare( strict_types=1 );

namespace Inc\Enums\Email;

enum EmailTemplateType: string {
	case OtpCode                = 'otp_code';
	case WelcomeWithCredentials = 'welcome_with_credentials';
	case CourseGranted          = 'course_granted';

	public function label(): string {
		return match ( $this ) {
			self::OtpCode                => 'OTP-код',
			self::WelcomeWithCredentials => 'Приветствие с данными для входа',
			self::CourseGranted          => 'Открыт доступ к курсу',
		};
	}
}
