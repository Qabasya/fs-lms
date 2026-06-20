<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Controllers\AdSyncController;
use Inc\Modules\AdSync\Controllers\AdSyncRestController;
use Inc\Modules\AdSync\Controllers\AdSyncSettingsController;
use Inc\Modules\AdSync\Schema\AdSchema;
use Inc\Services\Application\ApplicationSettingsService;

/**
 * Class AdSyncModule
 *
 * Bootstrap изолируемого модуля AdSync (создание учёток в AD через заявки).
 * Единственная точка входа модуля; регистрируется одной строкой в `Init::getServices()`.
 * Ядро о внутренностях модуля не знает — связь только через generic-хуки (см. .docs/WpToADTasks.md).
 *
 * Уровни выключения (§2.3):
 *  1) тумблер в опции `fs_lms_ad_sync.enabled`;
 *  2) константа `FS_LMS_AD_SYNC` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/AdSync/` + этой строки в `Init`.
 *
 * @package Inc\Modules\AdSync
 */
class AdSyncModule implements ServiceInterface {

	public function __construct(
		private readonly AdSyncSettingsController   $settings,
		private readonly AdSyncController           $runtime,
		private readonly AdSyncRestController       $rest,
		private readonly AdSchema                   $schema,
		private readonly AdSyncConfig               $config,
		private readonly ApplicationSettingsService $applicationSettings,
	) {}

	public function register(): void {
		// Admin-настройки (UI + сохранение) — всегда: чтобы можно было настроить и включить модуль.
		$this->settings->register();

		// Рантайм только при включённом флаге И включённой привязке заявки к направлению:
		// без `subject_key` Python не сможет выбрать AD-группу, поэтому AD-провижн без привязки — инертен.
		if ( ! $this->config->isEnabled() || ! $this->applicationSettings->isBindToSubject() ) {
			return;
		}

		// Своя таблица очереди (idempotent, version-gated) — только когда модуль реально работает.
		$this->schema->ensure();

		// Generic-сеймы ядра: provision-в-очередь при создании заявки + notice/poll в ответ apply + статус-AJAX.
		$this->runtime->register();

		// REST-эндпоинты для Python-сервиса (pull): GET /ad/jobs, POST /ad/ack.
		// [Этап 3+: deprovision/promote — fs_lms_student_enrolled / fs_lms_application_expired / ...]
		$this->rest->register();
	}
}
