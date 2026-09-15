<?php

declare( strict_types=1 );

namespace Inc\Core\Assets;

use Inc\Core\BaseController;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class AdminAssets
 *
 * Ассеты админки: гейт по экрану (AdminScreenContext), медиатека/редактор,
 * базовый стек (common + admin) и локализация window-переменных
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

		// Медиатека — только там, где её открывают: на списках она стоила ~25 скриптов и ~80 КБ HTML.
		if ( $ctx->needsMedia() ) {
			wp_enqueue_media();
		}

		// Редактор шагов урока и конструктор курса: wp.editor.initialize() и Quicktags.
		// wp_enqueue_editor() подключает tinymce, wp-tinymce, editor и quicktags.
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
	 * Базовый стек админки: общий и админский бандлы.
	 *
	 * filemtime() — версионирование (кеш-бастинг). Font Awesome не подключается: иконки —
	 * `Inc\Enums\Ui\Icon` и `src/js/common/icons.js`, а блокирующий CSS с CDN грузился впустую.
	 *
	 * @return void
	 */
	private function enqueueAdminBase(): void {
		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			array(),
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

		// Только jQuery: `wp.api` и `wp.i18n` в src/js не используются, а editor/quicktags
		// подключает wp_enqueue_editor() на экранах редактора шагов (AdminScreenContext::needsEditor()).
		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			$this->url( 'assets/js/admin.min.js' ),
			array( 'jquery' ),
			filemtime( $this->path( 'assets/js/admin.min.js' ) ),
			true
		);
	}
}
