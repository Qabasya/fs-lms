<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Menu;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\VideoLibrary\Callbacks\VideoLibrarySettingsCallbacks;
use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Services\RecordingAlertService;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class VideoLibrarySettingsController
 *
 * Admin-настройки модуля VideoLibrary: секция в табе «Конфигурация» через generic-хук
 * ядра `fs_lms_config_sections`, регистрация в Dashboard (`fs_lms_dashboard_modules`),
 * тумблер (`fs_lms_module_toggle_video_library`) и собственный admin-JS
 * (модуль self-contained, не лезет в core-бандл). Паттерн — AdSyncSettingsController.
 *
 * Секция показывается только при включённом модуле; enable-тумблер живёт на Dashboard.
 *
 * @package Inc\Modules\VideoLibrary\Controllers
 */
class VideoLibrarySettingsController extends BaseController {

	use Sanitizer;

	/** Собственное имя AJAX-действия сохранения (вне core AjaxHook — изоляция). */
	public const SAVE_ACTION = 'fs_lms_video_library_save';

	/** Экспорт групповой части `groups.yaml` сервиса fs-video-uploader. */
	public const EXPORT_GROUPS_ACTION = 'fs_lms_video_library_export_groups';

	public function __construct(
		private readonly VideoLibrarySettingsCallbacks $callbacks,
		private readonly VideoLibraryConfig            $config,
		private readonly RecordingAlertService         $alerts,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SAVE_ACTION, array( $this->callbacks, 'ajaxSaveSettings' ) );
		add_action( 'wp_ajax_' . self::EXPORT_GROUPS_ACTION, array( $this->callbacks, 'ajaxExportGroups' ) );
		add_action( 'fs_lms_config_sections', array( $this, 'renderSection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_notices', array( $this, 'renderPendingNotice' ) );
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_video_library', array( $this, 'onToggle' ) );
	}

	/**
	 * Алёрт З3: на страницах плагина — плашка «N занятий прошли без записи» со ссылкой
	 * в секцию модуля, где их можно починить. На самой странице настроек не дублируется
	 * (там живёт сам блок). Счётчик — из кэша (`countPendingCached`), точное число
	 * показывает блок в секции.
	 */
	public function renderPendingNotice(): void {
		if ( ! $this->config->isEnabled() || ! current_user_can( Capability::Admin->value ) ) {
			return;
		}

		$page = $this->sanitizeGetKey( 'page' );
		if ( ! str_starts_with( $page, 'fs_' ) || Menu::Settings->value === $page ) {
			return;
		}

		$count = $this->alerts->countPendingCached();
		if ( $count <= 0 ) {
			return;
		}

		$url = add_query_arg(
			array( 'page' => Menu::Settings->value, 'tab' => 'tab-7' ),
			admin_url( 'admin.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf(
				/* translators: %d: количество занятий без записи. */
				_n(
					'FS LMS: %d проведённое занятие осталось без записи.',
					'FS LMS: %d проведённых занятий остались без записи.',
					$count,
					'fs-lms'
				),
				$count
			) ),
			esc_url( $url ),
			esc_html__( 'Привязать запись', 'fs-lms' )
		);
	}

	/**
	 * Рендерит секцию настроек видеотеки. Вызывается из generic-хука ядра.
	 *
	 * @param array $subjects Список предметов (из шаблона таба конфигурации).
	 */
	public function renderSection( array $subjects = array() ): void {
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$config = $this->config;
		require $this->path( 'inc/Modules/VideoLibrary/templates/settings-section.php' );
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$modules[] = array(
			'id'           => 'video_library',
			'title'        => 'Видеозаписи занятий (S3)',
			'description'  => 'Приём записей занятий от сервиса fs-video-uploader (push в REST), привязка к занятиям и выдача ученикам временными presigned-ссылками из приватного S3 Beget. При отключении исчезает секция «Видеозаписи занятий» в Конфигурации, записи в плеере не показываются.',
			'enabled'      => $this->config->isEnabled(),
			'const_locked' => defined( 'FS_LMS_VIDEO_LIBRARY' ),
			'const_key'    => 'FS_LMS_VIDEO_LIBRARY',
		);

		return $modules;
	}

	public function onToggle( bool $enabled ): void {
		$this->config->save( array( 'enabled' => $enabled ) );
	}

	public function enqueueAssets( string $hook ): void {
		if ( 'fs_lms_settings' !== $this->sanitizeGetKey( 'page' ) ) {
			return;
		}

		// Выключенный модуль свой JS не грузит (секция настроек всё равно скрыта).
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$rel  = 'inc/Modules/VideoLibrary/assets/admin.js';
		$path = $this->path( $rel );
		wp_enqueue_script(
			'fs-lms-video-library',
			$this->url( $rel ),
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
		wp_localize_script( 'fs-lms-video-library', 'fsLmsVideoLibrary', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => Nonce::Config->create(),
			'actions' => array(
				'save'         => self::SAVE_ACTION,
				'exportGroups' => self::EXPORT_GROUPS_ACTION,
				'lessons'      => VideoLibraryController::LESSONS_ACTION,
				'attach'       => VideoLibraryController::ATTACH_ACTION,
				'detach'       => VideoLibraryController::DETACH_ACTION,
				'pending'      => VideoLibraryController::PENDING_ACTION,
				'setUrl'       => VideoLibraryController::SET_URL_ACTION,
			),
		) );
	}
}
