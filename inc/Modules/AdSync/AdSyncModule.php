<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Controllers\AdAuditLabelController;
use Inc\Modules\AdSync\Controllers\AdSyncController;
use Inc\Modules\AdSync\Cli\AdSyncCommand;
use Inc\Modules\AdSync\Controllers\AdSyncCronController;
use Inc\Modules\AdSync\Controllers\AdSyncSettingsController;
use Inc\Modules\AdSync\Schema\AdSchema;

/**
 * Class AdSyncModule
 *
 * Bootstrap изолируемого модуля AdSync (создание учёток в AD через заявки).
 * Единственная точка входа модуля; регистрируется одной строкой в `Init::getServices()`.
 * Ядро о внутренностях модуля не знает — связь только через generic-хуки
 * (см. .docs/AdSyncPythonService.md, .docs/FS_LMS_API.md).
 *
 * Модель — push: сайт подписывает задание и шлёт его серверу AdSync в офисе
 * (`POST /v1/jobs`), результат приходит в том же ответе.
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
		private readonly AdSyncCronController       $cron,
		private readonly AdSyncCommand              $cli,
		private readonly AdSchema                   $schema,
		private readonly AdSyncConfig               $config,
		private readonly AdAuditLabelController     $auditLabels,
	) {}

	public function register(): void {
		// Admin-настройки (UI + сохранение) — всегда: чтобы можно было настроить и включить модуль.
		$this->settings->register();

		// Подписи действий модуля в журнале «Зачисления» — всегда: старые записи читаются и без модуля.
		$this->auditLabels->register();

		// Рантайм только при включённом флаге модуля. Привязка к направлению независима:
		// включение модуля само по себе провижнит учётки; без привязки subject_key пустой —
		// Python создаёт учётку без группы направления.
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		// Своя таблица очереди (idempotent, version-gated) — только когда модуль реально работает.
		$this->schema->ensure();

		// Generic-сеймы ядра: provision-в-очередь при создании заявки + notice/poll в ответ apply + статус-AJAX.
		$this->runtime->register();

		// Доставка в офис (push): раз в минуту — ретраи и хвост очереди, раз в сутки — сверка.
		// Входящих REST-эндпоинтов у модуля нет: сайт сам шлёт задания серверу в офисе.
		$this->cron->register();

		// wp fs-lms ad … — ручной прогон очереди, проверка связи, сверка (только под WP-CLI).
		$this->cli->register();
	}
}
