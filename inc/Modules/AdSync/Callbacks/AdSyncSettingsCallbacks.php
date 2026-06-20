<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Services\Application\ApplicationSettingsService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdSyncSettingsCallbacks
 *
 * AJAX-сохранение настроек модуля AdSync (тумблер + TTL). Маппинг «направление → группа» НЕ хранится
 * в WP — Python решает группу по предмету сам. Зависит только от публичных сервисов ядра (модуль → ядро).
 *
 * @package Inc\Modules\AdSync\Callbacks
 */
class AdSyncSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly AdSyncConfig               $config,
		private readonly ApplicationSettingsService $applicationSettings,
	) {
		parent::__construct();
	}

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );

		$enabled = $this->sanitizeBool( 'ad_sync_enabled' );

		// Зависимость: AD-синхронизацию нельзя включить без «Привязки заявки к направлению»
		// (иначе у заявки нет subject_key и Python не выберет группу).
		if ( $enabled && ! $this->applicationSettings->isBindToSubject() ) {
			$this->error( 'Сначала включите «Привязать заявку к направлению» в разделе «Настройка заявок».' );
		}

		$this->config->save( array(
			'enabled' => $enabled,
		) );

		$this->success( array( 'message' => 'Настройки синхронизации сохранены.' ) );
	}
}
