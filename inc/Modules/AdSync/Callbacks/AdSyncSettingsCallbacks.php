<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Shared\Traits\Authorizer;

/**
 * Class AdSyncSettingsCallbacks
 *
 * AJAX-обработчики настроек модуля AdSync.
 * Включение/выключение модуля перенесено на Dashboard (fs_lms_module_toggle_ad_sync).
 * HMAC-секрет генерируется на клиенте и не хранится в БД, поэтому сохраняемых полей нет.
 *
 * @package Inc\Modules\AdSync\Callbacks
 */
class AdSyncSettingsCallbacks extends BaseController {

	use Authorizer;

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );
		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}
}
