<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Person\PersonUpdateCallbacks;
use Inc\Callbacks\Person\PersonViewCallbacks;
use Inc\Callbacks\Person\PiiRevealCallbacks;
use Inc\Callbacks\Person\RepresentativeCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Enums\Capability;

class PiiController extends AjaxController {

	public function __construct(
		private readonly PiiRevealCallbacks      $revealCallbacks,
		private readonly PersonViewCallbacks     $viewCallbacks,
		private readonly PersonUpdateCallbacks   $updateCallbacks,
		private readonly RepresentativeCallbacks $representativeCallbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerPersonDetailPage' ) );

		add_action( 'init',             array( $this, 'addExportRewriteRule' ) );
		add_filter( 'query_vars',       array( $this, 'addExportQueryVar' ) );
		add_filter( 'template_include', array( $this, 'handleExportDownload' ) );

		parent::register();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::RevealPiiField,        $this->revealCallbacks ),
			array( AjaxHook::RevealAllPersonPii,    $this->revealCallbacks ),
			array( AjaxHook::RequestPiiDeletion,    $this->updateCallbacks ),
			array( AjaxHook::AddRepresentative,     $this->representativeCallbacks ),
			array( AjaxHook::ReplaceRepresentative, $this->representativeCallbacks ),
			array( AjaxHook::UpdatePerson,          $this->updateCallbacks ),
			array( AjaxHook::GetPersonData,         $this->viewCallbacks ),
		);
	}

	public function registerPersonDetailPage(): void {
		add_submenu_page(
			null,
			'Карточка',
			'',
			Capability::ManagePersons->value,
			'fs-lms-person-detail',
			array( $this->viewCallbacks, 'renderPersonDetailPage' )
		);
	}

	public function addExportRewriteRule(): void {
		add_rewrite_rule(
			'^lms/export/([a-zA-Z0-9]+)/?$',
			'index.php?fs_lms_page=lms_export&fs_lms_token=$matches[1]',
			'top'
		);
	}

	public function addExportQueryVar( array $vars ): array {
		$vars[] = 'fs_lms_token';
		return $vars;
	}

	/**
	 * Отдаёт файл экспорта по одноразовому токену и удаляет его.
	 * Transient хранит массив: ['file' => path, 'filename' => name, 'content_type' => mime].
	 */
	public function handleExportDownload( string $template ): string {
		if ( 'lms_export' !== get_query_var( 'fs_lms_page' ) ) {
			return $template;
		}

		$token = sanitize_key( get_query_var( 'fs_lms_token' ) );
		$meta  = get_transient( 'fs_lms_export_' . $token );

		if ( ! is_array( $meta ) || empty( $meta['file'] ) || ! file_exists( (string) $meta['file'] ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return get_404_template();
		}

		delete_transient( 'fs_lms_export_' . $token );

		$filename    = (string) ( $meta['filename']     ?? 'export' );
		$contentType = (string) ( $meta['content_type'] ?? 'application/octet-stream' );

		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Type: ' . $contentType );
		nocache_headers();

		readfile( (string) $meta['file'] );
		unlink( (string) $meta['file'] );

		exit;
	}
}
