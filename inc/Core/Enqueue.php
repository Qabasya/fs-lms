<?php

declare(strict_types=1);

namespace Inc\Core;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\AjaxHook;
use Inc\Enums\Nonce;

class Enqueue extends BaseController implements ServiceInterface {
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
	}

	public function enqueue_admin_assets(): void {
		wp_enqueue_media();

		wp_enqueue_style(
			'fs-lms-admin-style',
			$this->url( 'assets/css/admin.min.css' ),
			[ 'wp-components' ],
			$this->plugin_version
		);

		$script_handle = 'fs-lms-admin-script';

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
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => Nonce::TaskCreation->create(),
				'subject_key' => $subject_key,
				'post_type'   => $screen->post_type,
			] );
		} elseif ( str_starts_with( $page, 'fs_subject_' ) ) {
			$subject_key = substr( $page, strlen( 'fs_subject_' ) );
			wp_localize_script( $script_handle, 'fs_lms_task_data', [
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => Nonce::TaskCreation->create(),
				'subject_key' => $subject_key,
				'post_type'   => $subject_key . '_tasks',
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
			'fs-lms-frontend-style',
			$this->url( 'assets/css/frontend.min.css' ),
			[],
			$this->plugin_version
		);

		wp_enqueue_script(
			'fs-lms-frontend-script',
			$this->url( 'assets/js/frontend.min.js' ),
			[ 'jquery' ],
			$this->plugin_version,
			true
		);
	}
}
