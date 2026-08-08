<?php

declare( strict_types=1 );

namespace Inc\Core\Assets;

use Inc\Core\BaseController;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdminAssets
 *
 * Ассеты админки: гейт по экрану (AdminScreenContext), медиатека/редактор,
 * базовый стек (Font Awesome + common + admin) и локализация window-переменных
 * из реестра AdminLocalizations.
 *
 * Выделен из Core\Enqueue (Т14.4).
 *
 * @package Inc\Core\Assets
 */
class AdminAssets extends BaseController {

	use Sanitizer;

	/** Хендл админского бандла — к нему цепляются все window-переменные админки. */
	private const ADMIN_SCRIPT_HANDLE = 'fs-lms-admin-script';

	public function __construct(
		private readonly AdminLocalizations $localizations,
		private readonly BundleLoader       $bundles,
	) {
		parent::__construct();
	}

	/**
	 * Подключение ресурсов в административной панели (хук admin_enqueue_scripts).
	 *
	 * @return void
	 */
	public function enqueue(): void {
		// get_current_screen() — возвращает объект текущего экрана админки
		$ctx = AdminScreenContext::from( get_current_screen(), $this->sanitizeText( 'page', 'GET' ) );

		// Подключаем ресурсы ТОЛЬКО на страницах плагина или наших CPT
		if ( ! $ctx->needsAssets() ) {
			return;
		}

		// wp_enqueue_media() — подключает медиа-библиотеку WordPress (для загрузки изображений)
		wp_enqueue_media();

		// На страницах CPT уроков и курсов нужен полный стек TinyMCE для wp.editor.initialize()
		// в редакторе шагов. wp_enqueue_editor() гарантирует загрузку tinymce + wp-tinymce.
		if ( $ctx->needsEditor() ) {
			wp_enqueue_editor();
		}

		$this->enqueueAdminBase();

		// Страница предмета: быстрое редактирование строк нативной таблицы.
		if ( $ctx->isSubjectPage() ) {
			// inline-edit-post — скрипт для быстрого редактирования постов в админке
			wp_enqueue_script( 'inline-edit-post' );
		}

		foreach ( $this->localizations->registry( $ctx ) as $varName => $data ) {
			if ( null !== $data ) {
				wp_localize_script( self::ADMIN_SCRIPT_HANDLE, $varName, $data );
			}
		}
	}

	/**
	 * Базовый стек админки: шрифт иконок, общий и админский бандлы.
	 *
	 * filemtime() — версионирование (кеш-бастинг).
	 *
	 * @return void
	 */
	private function enqueueAdminBase(): void {
		$this->bundles->enqueueFontAwesome();

		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			array( 'fs-lms-fontawesome' ),
			filemtime( $this->path( 'assets/css/common.min.css' ) )
		);

		wp_enqueue_style(
			'fs-lms-admin-style',
			$this->url( 'assets/css/admin.min.css' ),
			array( 'wp-components', 'fs-lms-common-style' ),
			filemtime( $this->path( 'assets/css/admin.min.css' ) )
		);

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			array( 'jquery' ),
			filemtime( $this->path( 'assets/js/common.min.js' ) ),
			true
		);

		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			$this->url( 'assets/js/admin.min.js' ),
			array( 'jquery', 'wp-api', 'wp-i18n', 'editor', 'quicktags' ),
			filemtime( $this->path( 'assets/js/admin.min.js' ) ),
			true
		);
	}
}
