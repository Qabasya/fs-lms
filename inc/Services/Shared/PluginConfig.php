<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

use Inc\Enums\Settings\ConfigConstant;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;

/**
 * Единая точка чтения «мягкой тройки» конфигурации плагина:
 * DADATA_API_TOKEN, FS_LMS_TEST_ENV, FS_LMS_OTP_BYPASS_CODE.
 *
 * Правило: константа из wp-config.php имеет приоритет над wp_options.
 * Ключи шифрования (FS_LMS_ENC_KEY / FS_LMS_HASH_SALT) читаются ТОЛЬКО
 * через defined() в PiiCryptoService — через этот класс не читаются.
 */
readonly class PluginConfig {

	public function __construct(
		private PluginConfigRepository $repository,
	) {}

	public function dadataToken(): string {
		if ( defined( 'DADATA_API_TOKEN' ) ) {
			return (string) DADATA_API_TOKEN;
		}
		return (string) ( $this->repository->get()['dadata_token'] ?? '' );
	}

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

	public function captchaSiteKey(): string {
		if ( defined( 'FS_LMS_CAPTCHA_SITE_KEY' ) ) {
			return (string) FS_LMS_CAPTCHA_SITE_KEY;
		}
		return (string) ( $this->repository->get()['captcha_site_key'] ?? '' );
	}

	public function captchaServerKey(): string {
		if ( defined( 'FS_LMS_CAPTCHA_SERVER_KEY' ) ) {
			return (string) FS_LMS_CAPTCHA_SERVER_KEY;
		}
		return (string) ( $this->repository->get()['captcha_server_key'] ?? '' );
	}

	public function isDefinedInConfig( ConfigConstant $constant ): bool {
		return defined( $constant->value );
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
		$data              = $this->repository->get();
		$dadataInConfig    = defined( 'DADATA_API_TOKEN' );
		$testEnvInConfig   = defined( 'FS_LMS_TEST_ENV' );
		$otpInConfig       = defined( 'FS_LMS_OTP_BYPASS_CODE' );
		$siteKeyInConfig   = defined( 'FS_LMS_CAPTCHA_SITE_KEY' );
		$serverKeyInConfig = defined( 'FS_LMS_CAPTCHA_SERVER_KEY' );

		return array(
			'dadata_token'    => array(
				'value'             => $dadataInConfig ? (string) DADATA_API_TOKEN : (string) ( $data['dadata_token'] ?? '' ),
				'defined_in_config' => $dadataInConfig,
				'editable'          => ! $dadataInConfig,
			),
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
			'captcha_site_key' => array(
				'value'             => $siteKeyInConfig ? (string) FS_LMS_CAPTCHA_SITE_KEY : (string) ( $data['captcha_site_key'] ?? '' ),
				'defined_in_config' => $siteKeyInConfig,
				'editable'          => ! $siteKeyInConfig,
			),
			'captcha_server_key' => array(
				'value'             => $serverKeyInConfig ? (string) FS_LMS_CAPTCHA_SERVER_KEY : (string) ( $data['captcha_server_key'] ?? '' ),
				'defined_in_config' => $serverKeyInConfig,
				'editable'          => ! $serverKeyInConfig,
			),
			'enc_key_set'     => $this->isEncKeySet(),
			'hash_salt_set'   => $this->isHashSaltSet(),
			// Настройка заявок (привязка к направлению).
			'applications_bind_to_subject' => (bool) ( $data['applications_bind_to_subject'] ?? false ),
			'direction_codes'              => is_array( $data['direction_codes'] ?? null ) ? $data['direction_codes'] : array(),
		);
	}
}
