<?php

declare( strict_types=1 );

namespace Inc\Controllers\Group;

use Inc\Controllers\System\AjaxController;

use Inc\Callbacks\Group\StudentGroupCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class StudentGroupController
 *
 * Контроллер для управления группами учеников.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для создания и удаления групп учеников.
 *
 * ### Архитектурная роль:
 *
 * Наследует абстрактный класс AjaxController, который реализует автоматическую регистрацию
 * AJAX-хуков через шаблонный метод ajaxActions(). Делегирует всю логику обработки
 * запросов классу StudentGroupCallbacks.
 */
class StudentGroupController extends AjaxController {
	/**
	 * Конструктор контроллера.
	 *
	 * @param StudentGroupCallbacks $student_group_callbacks Коллбеки для операций с группами
	 */
	public function __construct(
		private readonly StudentGroupCallbacks $student_group_callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список защищённых AJAX-действий для регистрации.
	 * Данные действия доступны только авторизованным пользователям с соответствующими правами.
	 *
	 * @return array Массив пар, каждая из которых содержит [AjaxHook, объект-коллбек]
	 */
	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::SaveStudentGroup,        $this->student_group_callbacks ),
			array( AjaxHook::UpdateStudentGroup,      $this->student_group_callbacks ),
			array( AjaxHook::DeleteStudentGroup,      $this->student_group_callbacks ),
			array( AjaxHook::GetStudentsByGroup,      $this->student_group_callbacks ),
			array( AjaxHook::GetGroupStudentsDetail,  $this->student_group_callbacks ),
			// Эпик 15 — открытые группы: пикер учеников + лёгкая запись существующих.
			array( AjaxHook::SearchStudentsForGroup,  $this->student_group_callbacks ),
			array( AjaxHook::AddStudentsToOpenGroup,  $this->student_group_callbacks ),
		);
	}
}