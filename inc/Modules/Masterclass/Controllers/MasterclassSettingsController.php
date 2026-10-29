<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Controllers;

use Inc\Core\BaseController;
use Inc\Enums\Wp\Menu;
use Inc\Enums\Wp\Nonce;
use Inc\Modules\Masterclass\Callbacks\MasterclassSettingsCallbacks;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class MasterclassSettingsController
 *
 * Настройки модуля Masterclass: вкладка «Мастер-классы» через фильтр ядра `fs_lms_settings_tabs`,
 * карточка Dashboard (`fs_lms_dashboard_modules`), тумблер (`fs_lms_module_toggle_masterclass`) и
 * собственный admin-JS (модуль self-contained, не лезет в core-бандл). Паттерн — VideoLibrarySettingsController.
 *
 * Регистрируется ВСЕГДА, и при выключенном модуле: иначе его нечем включить. Вкладка и JS показываются только
 * при включённом модуле; enable-тумблер живёт на Dashboard.
 *
 * @package Inc\Modules\Masterclass\Controllers
 */
class MasterclassSettingsController extends BaseController {

	use Sanitizer;

	/** Собственное имя AJAX-действия сохранения (вне core AjaxHook — изоляция). */
	public const SAVE_ACTION = 'fs_lms_masterclass_save_settings';

	/** Вкладка на странице «Настройки». */
	public const TAB = 'tab-masterclasses';

	public function __construct(
		private readonly MasterclassSettingsCallbacks $callbacks,
		private readonly MasterclassConfig $config,
		private readonly MasterclassCronController $cron,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::SAVE_ACTION, array( $this->callbacks, 'ajaxSave' ) );
		add_filter( 'fs_lms_settings_tabs', array( $this, 'registerSettingsTab' ) );
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_masterclass', array( $this, 'onToggle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Вкладка настроек; настройки отдаются шаблону через `data` вкладки (партиал сам в опции не лезет).
	 * Ключ данных не должен совпадать с переменными страницы настроек (`$config` там — массив настроек плагина):
	 * `extract( …, EXTR_SKIP )` их не перезапишет, и шаблон получит чужое значение.
	 *
	 * @param array<string, array<string, mixed>> $tabs
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function registerSettingsTab( array $tabs ): array {
		if ( $this->config->isEnabled() ) {
			$tabs[ self::TAB ] = array(
				'title' => 'Мастер-классы',
				'path'  => $this->path( 'inc/Modules/Masterclass/templates/settings-tab.php' ),
				'data'  => array( 'masterclass_config' => $this->config ),
			);
		}

		return $tabs;
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$modules[] = array(
			'id'           => 'masterclass',
			'title'        => 'Мастер-классы',
			'description'  => 'Занятия во внешних школах: временные аккаунты для детей (student-N), флаеры с логином и паролем, вход в плеер без регистрации, воронка «вошёл → вернулся домой → записался». При отключении исчезают вкладка «Мастер-классы» в настройках и страница в меню, вход временных аккаунтов и очистка по расписанию останавливаются.',
			'enabled'      => $this->config->isEnabled(),
			'const_locked' => defined( MasterclassConfig::TOGGLE_CONSTANT ),
			'const_key'    => MasterclassConfig::TOGGLE_CONSTANT,
		);

		return $modules;
	}

	/** Тумблер Dashboard: флаг пишется в опцию модуля, при выключении снимается событие тика. */
	public function onToggle( bool $enabled ): void {
		$this->config->save( array( 'enabled' => $enabled ) );

		if ( ! $enabled ) {
			$this->cron->unschedule();
		}
	}

	public function enqueueAssets(): void {
		if ( Menu::Settings->value !== $this->sanitizeGetKey( 'page' ) || self::TAB !== $this->sanitizeGetKey( 'tab' ) ) {
			return;
		}

		// Выключенный модуль свой JS не грузит (вкладка всё равно скрыта).
		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$rel  = 'inc/Modules/Masterclass/assets/settings.js';
		$path = $this->path( $rel );
		wp_enqueue_script(
			'fs-lms-masterclass-settings',
			$this->url( $rel ),
			array( 'jquery' ),
			file_exists( $path ) ? (string) filemtime( $path ) : $this->plugin_version,
			true
		);
		wp_localize_script(
			'fs-lms-masterclass-settings',
			'fsLmsMasterclassSettings',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::SAVE_ACTION,
				'nonce'   => Nonce::Config->create(),
			)
		);
	}
}
