<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum EmailTemplateType: string {
	case OtpCode                 = 'otp_code';
	case PasswordSetup           = 'password_setup';
	case ApplicationConfirmation = 'application_confirmation';
	case ApplicationReady        = 'application_ready';
	case Rejection               = 'rejection';
	case NewRepresentative       = 'new_representative';
	case WelcomeWithCredentials  = 'welcome_with_credentials';
}
