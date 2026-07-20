<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdSyncSettingsCallbacks
 *
 * AJAX-обработчики настроек модуля AdSync.
 * Включение/выключение модуля перенесено на Dashboard (fs_lms_module_toggle_ad_sync).
 * HMAC-секрет генерируется на клиенте и не хранится в БД. Сохраняемое поле —
 * `provision_subjects`: направления, по которым создаются доменные учётки.
 *
 * @package Inc\Modules\AdSync\Callbacks
 */
class AdSyncSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AdSyncConfig      $config,
		private readonly SubjectRepository $subjects,
	) {
		parent::__construct();
	}

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce проверен в authorize() выше; каждый элемент санитизируется ниже.
		$raw = wp_unslash( $_POST['provision_subjects'] ?? array() );
		$raw = is_array( $raw ) ? $raw : array();

		// Валидация по readAll() (не readActive): уже сохранённый архивный предмет не должен выпадать молча.
		$validKeys = array_map( static fn( $s ) => $s->key, $this->subjects->readAll() );
		$keys      = array();
		foreach ( $raw as $key ) {
			$key = $this->sanitizeKeyValue( $key );
			if ( '' !== $key && in_array( $key, $validKeys, true ) ) {
				$keys[] = $key;
			}
		}

		$this->config->save( array( 'provision_subjects' => array_values( array_unique( $keys ) ) ) );

		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}
}
