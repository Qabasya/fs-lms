<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\EnrollmentCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Enums\Capability;

/**
 * Class EnrollmentController
 *
 * Контроллер списка заявок и операций зачисления в административной панели.
 *
 * @package Inc\Controllers
 */
class EnrollmentController extends AjaxController {

	public function __construct(
		private readonly EnrollmentCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerAdminPages' ) );
		$this->registerAjaxHooks();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::EnrollStudent,              $this->callbacks ),
			array( AjaxHook::RejectApplication,          $this->callbacks ),
			array( AjaxHook::MoveApplicationToTrash,     $this->callbacks ),
			array( AjaxHook::RestoreApplicationFromTrash, $this->callbacks ),
			array( AjaxHook::EmptyApplicationsTrash,     $this->callbacks ),
		);
	}

	public function registerAdminPages(): void {
		add_submenu_page(
			'fs-lms',
			'Заявки',
			'Заявки',
			Capability::ManageApplications->value,
			'fs-lms-applications',
			array( $this->callbacks, 'renderApplicationsListPage' )
		);

		// Скрытая страница карточки — открывается по ?page=fs-lms-application-detail&id=N
		add_submenu_page(
			null,
			'Заявка',
			'',
			Capability::ManageApplications->value,
			'fs-lms-application-detail',
			array( $this->callbacks, 'renderApplicationDetailPage' )
		);
	}
}