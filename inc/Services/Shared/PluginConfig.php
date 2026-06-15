<?php

declare( strict_types=1 );

namespace Inc\Services\Shared;

use Inc\Enums\ConfigConstant;
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
		$data            = $this->repository->get();
		$dadataInConfig  = defined( 'DADATA_API_TOKEN' );
		$testEnvInConfig = defined( 'FS_LMS_TEST_ENV' );
		$otpInConfig     = defined( 'FS_LMS_OTP_BYPASS_CODE' );

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
			'enc_key_set'     => $this->isEncKeySet(),
			'hash_salt_set'   => $this->isHashSaltSet(),
		);
	}
}
