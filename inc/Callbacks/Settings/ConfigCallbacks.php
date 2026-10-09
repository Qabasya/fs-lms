<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class ConfigCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PluginConfigRepository    $configRepository,
		private readonly PersonDocumentsRepository $documentsRepository,
	) {
		parent::__construct();
	}

	public function ajaxSaveConfig(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$this->configRepository->save( array_merge(
			array(
				'test_env'        => $this->sanitizeBool( 'test_env' ),
				'otp_bypass_code' => $this->sanitizeText( 'otp_bypass_code' ),
				'brand_logo_id'   => $this->sanitizeInt( 'brand_logo_id' ),
				'consultation_url' => esc_url_raw( $this->sanitizeText( 'consultation_url' ) ),
			),
			$this->examSettings()
		) );

		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}

	/**
	 * Настройки экзаменов для гостей (11a.6): сохраняются только присланные поля — форма без секции (WooCommerce выключен)
	 * не должна обнулять товары и лимиты. Значения приводятся к границам при чтении ({@see \Inc\Services\Shared\PluginConfig}).
	 *
	 * @return array<string, int|string>
	 */
	private function examSettings(): array {
		$ints = array(
			'exam_product_9', 'exam_product_11', 'exam_hold_minutes', 'exam_ip_active_holds', 'exam_ip_hourly',
			'exam_source_active_holds', 'exam_guest_retention_days', 'exam_unpaid_retention_days',
		);
		$texts = array( 'center_phone', 'center_email', 'center_hours', 'center_address' );

		$settings = array();
		foreach ( $ints as $key ) {
			if ( $this->hasParam( $key ) ) {
				$settings[ $key ] = $this->sanitizeInt( $key );
			}
		}
		foreach ( $texts as $key ) {
			if ( $this->hasParam( $key ) ) {
				$settings[ $key ] = $this->sanitizeText( $key );
			}
		}

		return $settings;
	}

	public function ajaxGenerateKey(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$type    = $this->sanitizeKey( 'type' );
		$confirm = $this->sanitizeBool( 'confirm' );

		if ( 'enc_key' === $type ) {
			if ( defined( 'FS_LMS_ENC_KEY' ) && '' !== FS_LMS_ENC_KEY && $this->documentsRepository->hasAny() && ! $confirm ) {
				$this->error( 'Существуют зашифрованные данные. Перегенерация ключа сделает их нечитаемыми. Подтвердите действие.' );
			}

			$value  = base64_encode( sodium_crypto_secretbox_keygen() );
			$define = "define( 'FS_LMS_ENC_KEY', '{$value}' );";

			$this->success( array(
				'value'  => $value,
				'define' => $define,
			) );
		}

		if ( 'hash_salt' === $type ) {
			$value  = bin2hex( random_bytes( 32 ) );
			$define = "define( 'FS_LMS_HASH_SALT', '{$value}' );";

			$this->success( array(
				'value'  => $value,
				'define' => $define,
			) );
		}

		$this->error( 'Неизвестный тип ключа.' );
	}
}
