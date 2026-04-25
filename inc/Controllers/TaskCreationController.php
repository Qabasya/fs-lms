<?php

namespace Inc\Controllers;

use Inc\Callbacks\TaskCreationCallbacks;
use Inc\Callbacks\TemplateManagerCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;

/**
 * Class TaskCreationController
 *
 * Контроллер для инициализации функционала создания и настройки заданий.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Вывод модального окна** — подключает HTML-шаблон модального окна создания задания в админ-футере.
 * 2. **Регистрация AJAX-обработчиков** — подключает хуки для создания заданий и управления шаблонами.
 *
 * ### Архитектурная роль:
 *
 * Делегирует бизнес-логику TaskCreationCallbacks (создание заданий) и TemplateManagerCallbacks (шаблоны).
 */
class TaskCreationController extends BaseController implements ServiceInterface {
	
	/**
	 * Конструктор.
	 *
	 * @param TaskCreationCallbacks    $task_creation_callbacks    Коллбеки для создания заданий
	 * @param TemplateManagerCallbacks $template_manager_callbacks Коллбеки для управления шаблонами
	 */
	public function __construct(
		private readonly TaskCreationCallbacks $task_creation_callbacks,
		private readonly TemplateManagerCallbacks $template_manager_callbacks
	) {
		parent::__construct();
	}
	
	/**
	 * Регистрирует компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// add_action() — регистрирует функцию на хук 'admin_footer'
		// 'admin_footer' — срабатывает в конце каждой страницы админ-панели (перед закрывающим тегом body)
		add_action(
			'admin_footer',
			function () {
				// get_current_screen() — возвращает объект текущего экрана админ-панели
				$screen = get_current_screen();
				$page   = sanitize_text_field( $_GET['page'] ?? '' );
				
				// str_contains() — проверяет наличие подстроки (PHP 8.0)
				$on_tasks_cpt    = $screen && str_contains( $screen->post_type, '_tasks' );
				// str_starts_with() — проверяет начало строки (PHP 8.0)
				$on_subject_page = str_starts_with( $page, 'fs_subject_' );
				
				// Показываем модалку только на страницах заданий или предметов
				if ( ! $on_tasks_cpt && ! $on_subject_page ) {
					return;
				}
				
				// substr() — извлекает ключ предмета из строки 'fs_subject_math' → 'math'
				$subject_key = $on_subject_page
					? substr( $page, strlen( 'fs_subject_' ) )
					: str_replace( '_tasks', '', $screen->post_type );
				
				// include_once — подключает PHP-файл с HTML-разметкой модального окна
				// $this->plugin_path — свойство родительского класса BaseController (путь к плагину)
				include_once $this->plugin_path . 'templates/components/modals/task-creation-modal.php';
			}
		);
		
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();
	}
	
	/**
	 * Регистрация всех AJAX-хуков контроллера.
	 *
	 * @return void
	 */
	private function registerAjaxHooks(): void {
		// Хуки для создания заданий (TaskCreationCallbacks)
		$taskCreationHooks = array(
			AjaxHook::GetTaskTypes,         // Получение типов заданий для выпадающего списка
			AjaxHook::GetTaskBoilerplates,  // Получение списка типовых условий для типа задания
			AjaxHook::CreateTask,           // Создание нового задания через модальное окно
		);
		
		// Хуки для управления шаблонами (TemplateManagerCallbacks)
		$templateManagerHooks = array(
			AjaxHook::GetTemplateStructure,  // Получение структуры полей шаблона (ConditionField)
			AjaxHook::SaveTaskBoilerplate,   // Сохранение типового условия (legacy режим)
			AjaxHook::GetTaskBoilerplate,    // Получение типового условия для редактора
		);
		
		// Регистрация хуков создания заданий
		foreach ( $taskCreationHooks as $hook ) {
			// add_action() для AJAX: WordPress автоматически добавляет префикс 'wp_ajax_'
			add_action(
				$hook->action(),
				array( $this->task_creation_callbacks, $hook->callbackMethod() )
			);
		}
		
		// Регистрация хуков управления шаблонами
		foreach ( $templateManagerHooks as $hook ) {
			add_action(
				$hook->action(),
				array( $this->template_manager_callbacks, $hook->callbackMethod() )
			);
		}
	}
}