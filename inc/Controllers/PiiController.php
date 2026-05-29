<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\PiiCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Enums\Capability;

/**
 * Class PiiController
 *
 * Контроллер для работы с персональными данными: reveal, экспорт, удаление,
 * управление представителями, страница "Люди" в adminке, эндпоинт скачивания экспорта.
 *
 * @package Inc\Controllers
 */
class PiiController extends AjaxController {

	public function __construct(
		private readonly PiiCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerPersonsPage' ) );
		add_action( 'init',             array( $this, 'addPiiExportRewriteRule' ) );
		add_filter( 'query_vars',       array( $this, 'addPiiExportQueryVar' ) );
		add_filter( 'template_include', array( $this, 'handlePiiExportDownload' ) );
		parent::register();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::RevealPiiField,        $this->callbacks ),
			array( AjaxHook::RequestPiiDeletion,    $this->callbacks ),
			array( AjaxHook::ExportPii,             $this->callbacks ),
			array( AjaxHook::AddRepresentative,     $this->callbacks ),
			array( AjaxHook::ReplaceRepresentative, $this->callbacks ),
			array( AjaxHook::UpdatePerson,          $this->callbacks ),
		);
	}

	public function registerPersonsPage(): void {
		add_submenu_page(
			'fs-lms',
			'Люди',
			'Люди',
			Capability::ManagePersons->value,
			'fs-lms-persons',
			array( $this->callbacks, 'renderPersonsPage' )
		);

		add_submenu_page(
			null,
			'Карточка',
			'',
			Capability::ManagePersons->value,
			'fs-lms-person-detail',
			array( $this->callbacks, 'renderPersonDetailPage' )
		);
	}

	public function addPiiExportRewriteRule(): void {
		add_rewrite_rule(
			'^lms/pii-export/([a-zA-Z0-9]+)/?$',
			'index.php?fs_lms_page=pii_export&fs_lms_token=$matches[1]',
			'top'
		);
	}

	public function addPiiExportQueryVar( array $vars ): array {
		$vars[] = 'fs_lms_token';

		return $vars;
	}

	/**
	 * Отдаёт файл экспорта по одноразовому токену.
	 */
	public function handlePiiExportDownload( string $template ): string {
		if ( 'pii_export' !== get_query_var( 'fs_lms_page' ) ) {
			return $template;
		}

		$token = sanitize_key( get_query_var( 'fs_lms_token' ) );
		$file  = get_transient( 'fs_lms_export_' . $token );

		if ( ! $file || ! file_exists( (string) $file ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return get_404_template();
		}

		delete_transient( 'fs_lms_export_' . $token );

		header( 'Content-Disposition: attachment; filename="pii-export.json"' );
		header( 'Content-Type: application/json; charset=utf-8' );
		nocache_headers();

		readfile( (string) $file );
		unlink( (string) $file );
		exit;
	}
}