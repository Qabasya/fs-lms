<?php

declare( strict_types=1 );

namespace Inc\Controllers\Enrollment;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Enrollment\ApplicationDataCallbacks;
use Inc\Callbacks\Enrollment\ApplicationTrashCallbacks;
use Inc\Callbacks\Enrollment\EnrollmentLifecycleCallbacks;
use Inc\Callbacks\Enrollment\ParentLinkCallbacks;
use Inc\Callbacks\Enrollment\UserCredentialsCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class EnrollmentController
 *
 * Контроллер списка заявок и операций зачисления в административной панели.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **AJAX-обработчики** — регистрация хуков для операций с заявками
 *    (зачисление, корзина, правка данных, родители, креды).
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков. После распила Т14.2
 * делегирует пяти доменным Callbacks-классам: жизненный цикл зачисления,
 * данные заявки (PII), корзина, связка с родителями, учётные данные.
 *
 * ### Примечания:
 *
 * - Список заявок (таб "Заявки") находится на странице "Пользователи" (?page=fs_lms_userlist&tab=tab-1)
 */
class EnrollmentController extends AjaxController {

	public function __construct(
		private readonly EnrollmentLifecycleCallbacks $lifecycle,
		private readonly ApplicationDataCallbacks     $data,
		private readonly ApplicationTrashCallbacks    $trash,
		private readonly ParentLinkCallbacks          $parents,
		private readonly UserCredentialsCallbacks     $credentials,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список AJAX-действий для регистрации (только для авторизованных пользователей).
	 *
	 * @return array
	 */
	protected function ajaxActions(): array {
		return array(
			// ── Жизненный цикл зачисления ──
			array( AjaxHook::EnrollStudent, $this->lifecycle ),
			array( AjaxHook::StartEnrollment, $this->lifecycle ),
			array( AjaxHook::CancelEnrollment, $this->lifecycle ),
			array( AjaxHook::RestoreFromArchive, $this->lifecycle ),
			array( AjaxHook::BulkRestoreFromArchive, $this->lifecycle ),
			array( AjaxHook::GetStudentGroups, $this->lifecycle ),

			// ── Данные заявки (PII) ──
			array( AjaxHook::UpdateApplicationData, $this->data ),
			array( AjaxHook::UpdateReviewData, $this->data ),
			array( AjaxHook::GetApplicationData, $this->data ),

			// ── Корзина заявок ──
			array( AjaxHook::MoveApplicationToTrash, $this->trash ),
			array( AjaxHook::RestoreApplicationFromTrash, $this->trash ),
			array( AjaxHook::DeleteApplication, $this->trash ),
			array( AjaxHook::EmptyApplicationsTrash, $this->trash ),

			// ── Родители ──
			array( AjaxHook::SelectExistingParent, $this->parents ),
			array( AjaxHook::RemoveParentAssignment, $this->parents ),
			array( AjaxHook::SearchParents, $this->parents ),

			// ── Учётные данные ──
			array( AjaxHook::RevealUserCredentials, $this->credentials ),
			array( AjaxHook::RegenerateUserPassword, $this->credentials ),
		);
	}

}
