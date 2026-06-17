<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Task\TaskCreationCallbacks;
use Inc\Callbacks\Task\TemplateManagerCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Services\PostTypeResolver;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class TaskCreationController
 *
 * Контроллер для создания новых заданий через модальное окно в админ-панели.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Вывод модального окна** — подключение HTML-шаблона модального окна создания задания в админ-футере.
 * 2. **Регистрация AJAX-обработчиков** — подключение хуков для получения типов заданий, шаблонов и создания заданий.
 *
 * ### Архитектурная роль:
 *
 * Наследует абстрактный класс AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику TaskCreationCallbacks (создание заданий) и TemplateManagerCallbacks (шаблоны).
 */
class TaskCreationController extends AjaxController {

	use Sanitizer;

	/**
	 * Конструктор контроллера.
	 *
	 * @param TaskCreationCallbacks    $task_creation_callbacks     Коллбеки для создания заданий
	 * @param TemplateManagerCallbacks $template_manager_callbacks  Коллбеки для управления шаблонами
	 */
	public function __construct(
		private readonly TaskCreationCallbacks    $task_creation_callbacks,
		private readonly TemplateManagerCallbacks $template_manager_callbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует компоненты контроллера.
	 * Переопределяет родительский метод для добавления вывода модального окна.
	 *
	 * @return void
	 */
	public function register(): void {
		// 'admin_footer' — хук для вывода HTML в подвале админ-панели
		add_action(
			'admin_footer',
			function () {
				// get_current_screen() — возвращает объект текущего экрана админки
				$screen = get_current_screen();
				$page   = $this->sanitizeText( 'page', 'GET' );

				// Проверка, находимся ли мы на странице CPT заданий, работ или на странице предмета
				$on_tasks_cpt    = $screen && PostTypeResolver::isTaskPostType( $screen->post_type );
				$on_works_cpt    = $screen && PostTypeResolver::isWorkPostType( $screen->post_type );
				$on_subject_page = str_starts_with( $page, 'fs_subject_' );

				if ( ! $on_tasks_cpt && ! $on_works_cpt && ! $on_subject_page ) {
					return;
				}

				// Извлечение ключа предмета из URL или из типа поста
				if ( $on_subject_page ) {
					$subject_key = substr( $page, strlen( 'fs_subject_' ) );
				} elseif ( $on_works_cpt ) {
					$subject_key = PostTypeResolver::subjectFromWorkPostType( $screen->post_type );
				} else {
					$subject_key = PostTypeResolver::subjectFromTaskPostType( $screen->post_type );
				}

				// Подключение шаблона модального окна
				include_once $this->plugin_path . 'templates/admin/components/modals/task-modal.php';
			}
		);

		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array Массив действий, каждое с хуком и объектом-коллбеком
	 */
	protected function ajaxActions(): array {
		return array(
			// Получение типов заданий для выпадающего списка
			array( AjaxHook::GetTaskTypes, $this->task_creation_callbacks ),
			// Получение списка типовых условий (boilerplate) для типа задания
			array( AjaxHook::GetTaskBoilerplates, $this->task_creation_callbacks ),
			// Создание нового задания
			array( AjaxHook::CreateTask, $this->task_creation_callbacks ),
			// Получение структуры полей шаблона (ConditionField)
			array( AjaxHook::GetTemplateStructure, $this->template_manager_callbacks ),
			// Сохранение типового условия (legacy режим)
			array( AjaxHook::SaveTaskBoilerplate, $this->template_manager_callbacks ),
			// Получение типового условия для редактора
			array( AjaxHook::GetTaskBoilerplate, $this->template_manager_callbacks ),
		);
	}
}