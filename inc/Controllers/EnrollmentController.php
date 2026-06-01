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
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация административных страниц** — добавление скрытой страницы карточки заявки.
 * 2. **AJAX-обработчики** — регистрация хуков для операций с заявками (зачисление, отклонение, корзина).
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику EnrollmentCallbacks.
 *
 * ### Примечания:
 *
 * - Список заявок (таб "Заявки") находится на странице "Пользователи" (?page=fs_lms_userlist&tab=tab-1)
 * - Для отображения карточки заявки создаётся скрытая подстраница (parent_slug = null)
 */
class EnrollmentController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param EnrollmentCallbacks $callbacks Коллбеки для операций с заявками и зачислением
	 */
	public function __construct(
		private readonly EnrollmentCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'admin_menu' — хук для регистрации страниц админ-панели
		add_action( 'admin_menu', array( $this, 'registerAdminPages' ) );

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
			// Зачисление студента
			array( AjaxHook::EnrollStudent, $this->callbacks ),
			// Перемещение заявки в корзину
			array( AjaxHook::MoveApplicationToTrash, $this->callbacks ),
			// Восстановление заявки из корзины
			array( AjaxHook::RestoreApplicationFromTrash, $this->callbacks ),
			// Очистка корзины (физическое удаление всех заявок со статусом Trash)
			array( AjaxHook::EmptyApplicationsTrash, $this->callbacks ),
			// Постоянное удаление одной заявки из корзины
			array( AjaxHook::DeleteApplication, $this->callbacks ),
			// Редактирование данных заявки
			array( AjaxHook::UpdateApplicationData, $this->callbacks ),
			// Редактирование данных заявки на проверке (ReadyForReview)
			array( AjaxHook::UpdateReviewData, $this->callbacks ),
			// Начало зачисления (ReadyForReview → Enrolling)
			array( AjaxHook::StartEnrollment, $this->callbacks ),
			// Отмена зачисления (Enrolling → ReadyForReview)
			array( AjaxHook::CancelEnrollment, $this->callbacks ),
			// Получение данных заявки (расшифрованных)
			array( AjaxHook::GetApplicationData, $this->callbacks ),
			// Получение групп по периоду и предмету
			array( AjaxHook::GetStudentGroups, $this->callbacks ),
			// Показать логин и пароль пользователя (расшифровать из user meta)
			array( AjaxHook::RevealUserCredentials, $this->callbacks ),
		);
	}

	/**
	 * Регистрирует административные страницы.
	 *
	 * @return void
	 */
	public function registerAdminPages(): void {
		// add_submenu_page() — добавляет подстраницу в меню WordPress
		// Параметры: parent_slug, page_title, menu_title, capability, menu_slug, callback
		// parent_slug = null — страница не отображается в боковом меню (скрытая)
		add_submenu_page(
			null,                                   // Не показывать в меню
			'Заявка',                               // Заголовок страницы (<title>)
			'',                                     // Название пункта меню (пустое — не отображается)
			Capability::ManageApplications->value,  // Необходимое право доступа
			'fs-lms-application-detail',            // Уникальный идентификатор (slug)
			array( $this->callbacks, 'renderApplicationDetailPage' )  // Коллбек отрисовки
		);
	}
}