<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\AjaxHook;
use Inc\Enums\Nonce;
use Inc\Repositories\TaxonomyRepository;

class Enqueue extends BaseController implements ServiceInterface {

	public function __construct( private readonly TaxonomyRepository $taxonomy_repository ) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'admin_footer', [ $this, 'render_confirm_modal' ] );
	}

	public function enqueue_admin_assets(): void {
		wp_enqueue_media();

		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			[],
			$this->plugin_version
		);

		wp_enqueue_style(
			'fs-lms-admin-style',
			$this->url( 'assets/css/admin.min.css' ),
			[ 'wp-components', 'fs-lms-common-style' ],
			$this->plugin_version
		);

		$script_handle = 'fs-lms-admin-script';

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			[ 'jquery' ],
			$this->plugin_version,
			true
		);

		wp_enqueue_script(
			$script_handle,
			$this->url( 'assets/js/admin.min.js' ),
			[ 'jquery', 'wp-api', 'wp-i18n', 'editor', 'quicktags' ],
			$this->plugin_version,
			true
		);

		$screen = get_current_screen();

		$page = sanitize_text_field( $_GET['page'] ?? '' );

		if ( is_admin() && $screen && str_contains( $screen->post_type, '_tasks' ) ) {
			$subject_key = str_replace( '_tasks', '', $screen->post_type );
			wp_localize_script( $script_handle, 'fs_lms_task_data', [
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'security'            => Nonce::TaskCreation->create(),
				'subject_key'         => $subject_key,
				'post_type'           => $screen->post_type,
				'required_taxonomies' => $this->getRequiredTaxonomies( $subject_key ),
			] );
		} elseif ( str_starts_with( $page, 'fs_subject_' ) ) {
			$subject_key = substr( $page, strlen( 'fs_subject_' ) );
			wp_localize_script( $script_handle, 'fs_lms_task_data', [
				'ajax_url'            => admin_url( 'admin-ajax.php' ),
				'security'            => Nonce::TaskCreation->create(),
				'subject_key'         => $subject_key,
				'post_type'           => $subject_key . '_tasks',
				'required_taxonomies' => $this->getRequiredTaxonomies( $subject_key ),
			] );
			wp_enqueue_script( 'inline-edit-post' );
		}

		wp_localize_script( $script_handle, 'fs_lms_vars', [
			'ajaxurl'       => admin_url( 'admin-ajax.php' ),
			'subject_nonce' => Nonce::Subject->create(),
			'manager_nonce' => Nonce::Manager->create(),
			'ajax_actions'  => AjaxHook::toJsArray(),
		] );
	}

	public function enqueue_frontend_assets(): void {
		wp_enqueue_style(
			'fs-lms-common-style',
			$this->url( 'assets/css/common.min.css' ),
			[],
			$this->plugin_version
		);

		wp_enqueue_style(
			'fs-lms-frontend-style',
			$this->url( 'assets/css/frontend.min.css' ),
			[ 'fs-lms-common-style' ],
			$this->plugin_version
		);

		wp_enqueue_script(
			'fs-lms-common-script',
			$this->url( 'assets/js/common.min.js' ),
			[ 'jquery' ],
			$this->plugin_version,
			true
		);

		wp_enqueue_script(
			'fs-lms-frontend-script',
			$this->url( 'assets/js/frontend.min.js' ),
			[ 'jquery', 'fs-lms-common-script' ],
			$this->plugin_version,
			true
		);
	}
	
	private function getRequiredTaxonomies( string $subject_key ): array {
		return array_values( array_map(
			fn( $dto ) => [ 'slug' => $dto->slug, 'name' => $dto->name ],
			array_filter(
				$this->taxonomy_repository->getBySubject( $subject_key ),
				fn( $dto ) => $dto->is_required
			)
		) );
	}

	/**
	 * Глобально рендерит HTML модалки подтверждения в админке.
	 */
	public function render_confirm_modal(): void {
		// Загружаем только на страницах нашего плагина (оптимизация)
		$page = sanitize_text_field( $_GET['page'] ?? '' );
		if ( ! str_starts_with( $page, 'fs_' ) ) {
			return;
		}
		
		// Надёжный путь от inc/Core/ до templates/components/modals/
		$modal_path = dirname( __DIR__, 2 ) . '/templates/components/modals/confirm-modal.php';
		
		if ( file_exists( $modal_path ) ) {
			require_once $modal_path;
		}
	}
}
