<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Controllers\VideoLibraryController;
use Inc\Modules\VideoLibrary\Controllers\VideoLibrarySettingsController;
use Inc\Modules\VideoLibrary\Controllers\VideoRestController;
use Inc\Modules\VideoLibrary\Schema\VideoSchema;

/**
 * Class VideoLibraryModule
 *
 * Bootstrap изолируемого модуля VideoLibrary (видеозаписи занятий: S3 Beget + push-REST
 * от сервиса fs-video-uploader + presigned-выдача в плеер). Единственная точка входа модуля;
 * регистрируется одной строкой в `Init::getServices()`. Ядро о внутренностях модуля не знает —
 * связь только через generic-швы (`fs_lms_recording_url`, публичный GroupLessonRepository).
 *
 * Уровни выключения (ModularArchitecture.md §3.2):
 *  1) тумблер в опции `fs_lms_video_library.enabled` (Dashboard);
 *  2) константа `FS_LMS_VIDEO_LIBRARY` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/VideoLibrary/` + этой строки в `Init`.
 *
 * @package Inc\Modules\VideoLibrary
 */
class VideoLibraryModule implements ServiceInterface {

	public function __construct(
		private readonly VideoLibrarySettingsController $settings,
		private readonly VideoLibraryController         $runtime,
		private readonly VideoRestController            $rest,
		private readonly VideoSchema                    $schema,
		private readonly VideoLibraryConfig             $config,
	) {}

	public function register(): void {
		// Admin-настройки (UI + Dashboard-тумблер) — всегда: чтобы модуль можно было включить.
		$this->settings->register();

		if ( ! $this->config->isEnabled() ) {
			return;
		}

		// Своя таблица реестра записей (idempotent, version-gated) — только когда модуль работает.
		$this->schema->ensure();

		// Фильтр presigned-выдачи + AJAX ручной привязки.
		$this->runtime->register();

		// REST-эндпоинт push-регистрации: POST /wp-json/fs-lms/v1/videos.
		$this->rest->register();
	}
}
