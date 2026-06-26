<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Settings;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\OptionsRepositories\PluginConfigRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\PersonDocumentsRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class ConfigCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PluginConfigRepository    $configRepository,
		private readonly PersonDocumentsRepository $documentsRepository,
		private readonly SubjectRepository         $subjectRepository,
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

	/**
	 * Сохраняет настройки заявок (отдельный блок): тумблер привязки + карта кодов направлений.
	 * Отдельное действие, чтобы сохранение этого блока не затирало настройки сервисов и наоборот.
	 *
	 * @return void
	 */
	public function ajaxSaveApplicationSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$bind  = $this->sanitizeBool( 'applications_bind_to_subject' );
		$codes = $this->sanitizeDirectionCodes();

		// Инвариант: привязка включена ⟹ нужен хотя бы один код направления, иначе гейт
		// на /lms/apply никого не пропустит и форму заявки будет невозможно открыть.
		if ( $bind && empty( $codes ) ) {
			$this->error( 'Чтобы включить привязку, задайте хотя бы один код направления — иначе форму заявки нельзя будет открыть.' );
		}

		$this->configRepository->save( array(
			'applications_bind_to_subject' => $bind,
			'direction_codes'              => $codes,
		) );

		$this->success( array( 'message' => 'Настройки заявок сохранены.' ) );
	}

	/**
	 * Санитизирует карту направлений `[subject_key => code]` из формы.
	 * Оставляет только реальные предметы с непустым кодом.
	 *
	 * @return array<string, string>
	 */
	private function sanitizeDirectionCodes(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce проверен в authorize() выше.
		$raw = wp_unslash( $_POST['direction_codes'] ?? array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$validKeys = array_map( static fn( $s ) => $s->key, $this->subjectRepository->readAll() );
		$result    = array();

		foreach ( $raw as $subjectKey => $code ) {
			$key  = $this->sanitizeKeyValue( $subjectKey );
			$code = trim( $this->sanitizeTextValue( $code ) );
			if ( '' !== $key && '' !== $code && in_array( $key, $validKeys, true ) ) {
				$result[ $key ] = $code;
			}
		}

		return $result;
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
