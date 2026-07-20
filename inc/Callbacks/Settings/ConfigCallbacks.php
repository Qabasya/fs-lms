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

		$this->configRepository->save( array(
			'test_env'           => $this->sanitizeBool( 'test_env' ),
			'otp_bypass_code'    => $this->sanitizeText( 'otp_bypass_code' ),
		) );

		$this->success( array( 'message' => 'Настройки сохранены.' ) );
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
