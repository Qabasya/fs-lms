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

	/**
	 * Белые IP (статический адрес сети, откуда подают заявки очно) — для них повышены IP-лимиты.
	 *
	 * Только константа `FS_LMS_TRUSTED_IPS` в wp-config.php, без опции в БД: адрес меняется
	 * редко, а правка из админки расширила бы круг тех, кто может ослабить защиту.
	 * Формат — точные адреса через запятую (или массив); маски подсетей не поддерживаются.
	 *
	 * @return string[]
	 */
	public function trustedIps(): array {
		if ( ! defined( 'FS_LMS_TRUSTED_IPS' ) ) {
			return array();
		}

		$raw  = FS_LMS_TRUSTED_IPS;
		$list = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		return array_values( array_filter(
			array_map( static fn( $ip ): string => trim( (string) $ip ), $list ),
			static fn( string $ip ): bool => false !== filter_var( $ip, FILTER_VALIDATE_IP )
		) );
	}

	/** Attachment ID логотипа кабинета; 0 — не задан. */
	public function brandLogoId(): int {
		return (int) ( $this->repository->get()['brand_logo_id'] ?? 0 );
	}

	/** URL логотипа кабинета; '' — не задан (фронт показывает дефолтный BrandMark). */
	public function brandLogoUrl(): string {
		$id = $this->brandLogoId();
		if ( $id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'medium' );

		return $url ?: '';
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
			'brand_logo'      => array(
				'id'  => $this->brandLogoId(),
				'url' => $this->brandLogoUrl(),
			),
		);
	}
}
