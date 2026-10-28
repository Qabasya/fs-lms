<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class MasterclassSettingsCallbacks
 *
 * AJAX-обработчик вкладки «Настройки → Мастер-классы». Включение модуля — на Dashboard
 * (`fs_lms_module_toggle_masterclass`), поэтому ключа `enabled` здесь нет.
 *
 * Сохраняются только присланные ключи: непришедшее остаётся прежним. Числа клампятся в границы,
 * время проверяется на формат и порядок — это делает {@see MasterclassConfig::normalize()}.
 *
 * @package Inc\Modules\Masterclass\Callbacks
 */
class MasterclassSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly MasterclassConfig $config,
	) {
		parent::__construct();
	}

	public function ajaxSave(): void {
		$this->authorize( Nonce::Config, Capability::ManageLmsPlatform );

		$partial = array();

		foreach ( array_keys( MasterclassConfig::INT_BOUNDS ) as $key ) {
			if ( $this->hasParam( $key ) ) {
				$partial[ $key ] = $this->sanitizeInt( $key );
			}
		}

		foreach ( array( 'start_time', 'end_time', 'banner_text' ) as $key ) {
			if ( $this->hasParam( $key ) ) {
				$partial[ $key ] = $this->sanitizeText( $key );
			}
		}

		try {
			if ( $this->hasParam( 'messenger_url' ) ) {
				$partial['messenger_url'] = $this->sanitizeHttpUrlOrNull( 'messenger_url' ) ?? '';
			}

			$this->config->save( $this->config->normalize( $partial ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );

			return;
		}

		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}
}
