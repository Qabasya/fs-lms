<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

use Inc\Repositories\OptionsRepositories\PluginConfigRepository;

/**
 * Единая точка чтения core-настроек «тестового окружения»:
 * FS_LMS_TEST_ENV, FS_LMS_OTP_BYPASS_CODE.
 *
 * Правило: константа из wp-config.php имеет приоритет над wp_options.
 * Ключи шифрования (FS_LMS_ENC_KEY / FS_LMS_HASH_SALT) читаются ТОЛЬКО
 * через defined() в PiiCryptoService. DaData-токен и ключи SmartCaptcha
 * переехали в свои модули (Inc\Modules\DaData / Inc\Modules\SmartCaptcha).
 */
readonly class PluginConfig {

	public function __construct(
		private PluginConfigRepository $repository,
	) {}

	public function isTestEnv(): bool {
		if ( defined( 'FS_LMS_TEST_ENV' ) ) {
			return true;
		}
		return (bool) ( $this->repository->get()['test_env'] ?? false );
	}

	public function otpBypassCode(): string {
		if ( defined( 'FS_LMS_OTP_BYPASS_CODE' ) ) {
			return (string) FS_LMS_OTP_BYPASS_CODE;
		}
		return (string) ( $this->repository->get()['otp_bypass_code'] ?? '' );
	}

	public function isEncKeySet(): bool {
		return defined( 'FS_LMS_ENC_KEY' ) && '' !== FS_LMS_ENC_KEY;
	}

	public function isHashSaltSet(): bool {
		return defined( 'FS_LMS_HASH_SALT' ) && '' !== FS_LMS_HASH_SALT;
	}

	/**
	 * Payload для шаблона таба конфигурации.
	 * Мягкая тройка — c префиллом значений. Ключи — только set-флаг, значение НЕ включается.
	 */
	public function viewState(): array {
		$data            = $this->repository->get();
		$testEnvInConfig = defined( 'FS_LMS_TEST_ENV' );
		$otpInConfig     = defined( 'FS_LMS_OTP_BYPASS_CODE' );

		return array(
			'test_env'        => array(
				'value'             => $this->isTestEnv(),
				'defined_in_config' => $testEnvInConfig,
				'editable'          => ! $testEnvInConfig,
			),
			'otp_bypass_code' => array(
				'value'             => $otpInConfig ? (string) FS_LMS_OTP_BYPASS_CODE : (string) ( $data['otp_bypass_code'] ?? '' ),
				'defined_in_config' => $otpInConfig,
				'editable'          => ! $otpInConfig,
			),
			'enc_key_set'     => $this->isEncKeySet(),
			'hash_salt_set'   => $this->isHashSaltSet(),
			// Настройка заявок (привязка к направлению).
			'applications_bind_to_subject' => (bool) ( $data['applications_bind_to_subject'] ?? false ),
			'direction_codes'              => is_array( $data['direction_codes'] ?? null ) ? $data['direction_codes'] : array(),
		);
	}
}
