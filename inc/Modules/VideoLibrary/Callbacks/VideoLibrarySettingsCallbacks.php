<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Shared\Traits\Authorizer;

/**
 * Class VideoLibrarySettingsCallbacks
 *
 * AJAX-обработчики настроек модуля VideoLibrary.
 * Включение/выключение модуля — на Dashboard (`fs_lms_module_toggle_video_library`).
 * HMAC-секрет и S3-реквизиты — константы wp-config (в БД не хранятся), поэтому
 * сохраняемых полей нет.
 *
 * @package Inc\Modules\VideoLibrary\Callbacks
 */
class VideoLibrarySettingsCallbacks extends BaseController {

	use Authorizer;

	public function ajaxSaveSettings(): void {
		$this->authorize( Nonce::Config, Capability::Admin );
		$this->success( array( 'message' => 'Настройки сохранены.' ) );
	}
}
