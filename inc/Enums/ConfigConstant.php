<?php

declare( strict_types=1 );

namespace Inc\Enums;

enum ConfigConstant: string {

	case DadataToken      = 'DADATA_API_TOKEN';
	case EncKey           = 'FS_LMS_ENC_KEY';
	case HashSalt         = 'FS_LMS_HASH_SALT';
	case TestEnv          = 'FS_LMS_TEST_ENV';
	case OtpBypassCode    = 'FS_LMS_OTP_BYPASS_CODE';
	case CaptchaSiteKey   = 'FS_LMS_CAPTCHA_SITE_KEY';
	case CaptchaServerKey = 'FS_LMS_CAPTCHA_SERVER_KEY';

	public function label(): string {
		return match ( $this ) {
			self::DadataToken      => 'DaData API-токен',
			self::EncKey           => 'Ключ шифрования ПДн',
			self::HashSalt         => 'Соль хеширования',
			self::TestEnv          => 'Тестовое окружение',
			self::OtpBypassCode    => 'Bypass-код OTP',
			self::CaptchaSiteKey   => 'SmartCaptcha: клиентский ключ',
			self::CaptchaServerKey => 'SmartCaptcha: серверный ключ',
		};
	}

	/** Ключи не хранятся в БД — только генерируются и копипастятся в wp-config.php */
	public function isSecret(): bool {
		return match ( $this ) {
			self::EncKey, self::HashSalt => true,
			default                      => false,
		};
	}
}
