<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\ExpulsionCallbacks;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;

class ExpulsionController extends AjaxController {

	public function __construct(
		private readonly ExpulsionCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		parent::register();
		add_action( 'admin_footer', array( $this, 'renderModal' ) );
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::ExpelStudent,         $this->callbacks ),
			array( AjaxHook::ExportExpelledRecord, $this->callbacks ),
		);
	}

	public function renderModal(): void {
		include $this->path( 'templates/admin/components/modals/expel-modal.php' );
	}
}
