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
 * Наследует BaseController для использования методов path() и plugin_path.
 *
 * Отвечает за:
 * - Регистрацию AJAX-обработчиков для создания и настройки заданий
 * - Вывод модального окна создания задания в админ-футере
 *
 * Делегирует выполнение бизнес-логики классам:
 * - TaskCreationCallbacks — создание заданий и получение типов
 * - TemplateManagerCallbacks — управление шаблонами и типовыми условиями
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class TaskCreationController extends BaseController implements ServiceInterface {
	/**
	 * Конструктор.
	 *
	 * @param TaskCreationCallbacks    $taskCreationCallbacks    Коллбеки для создания заданий
	 * @param TemplateManagerCallbacks $templateManagerCallbacks Коллбеки для управления типами заданий
	 */
	public function __construct(
		private readonly TaskCreationCallbacks $taskCreationCallbacks,
		private readonly TemplateManagerCallbacks $templateManagerCallbacks
	) {
		parent::__construct();
	}
	
	/**
	 * Регистрирует компоненты контроллера.
	 *
	 * Вызывается из Init.php при инициализации плагина.
	 *
	 * Процесс регистрации:
	 * 1. Вывод HTML модального окна создания задания в футере админки
	 * 2. Регистрация AJAX-обработчиков для работы с заданиями
	 *
	 * @return void
	 */
	public function register(): void {
		// Вывод HTML модального окна создания задания в футере админ-панели
		add_action( 'admin_footer', function () {
			$screen = get_current_screen();
			$page   = sanitize_text_field( $_GET['page'] ?? '' );
			
			// Определяем, находимся ли мы на странице CPT заданий или на странице предмета
			$on_tasks_cpt    = $screen && str_contains( $screen->post_type, '_tasks' );
			$on_subject_page = str_starts_with( $page, 'fs_subject_' );
			
			// Если не на нужной странице — выходим
			if ( ! $on_tasks_cpt && ! $on_subject_page ) {
				return;
			}
			
			// Извлекаем ключ предмета из URL или из post_type
			$subject_key = $on_subject_page
				? substr( $page, strlen( 'fs_subject_' ) )
				: str_replace( '_tasks', '', $screen->post_type );
			
			// Подключаем шаблон модального окна
			include_once $this->plugin_path . 'templates/components/modals/task-creation-modal.php';
		} );
		
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();
	}
	
	/**
	 * Регистрация всех AJAX-хуков контроллера.
	 *
	 * Разделяет хуки на две группы:
	 * - taskCreationHooks — для создания заданий и получения типов
	 * - templateManagerHooks — для управления шаблонами и типовыми условиями
	 *
	 * @return void
	 */
	private function registerAjaxHooks(): void {
		// === Хуки для создания заданий (TaskCreationCallbacks) ===
		$taskCreationHooks = [
			AjaxHook::GetTaskTypes,         // Получение типов заданий
			AjaxHook::GetTaskBoilerplates,  // Получение списка типовых условий
			AjaxHook::CreateTask,           // Создание нового задания
		];
		
		// === Хуки для управления шаблонами (TemplateManagerCallbacks) ===
		$templateManagerHooks = [
			AjaxHook::GetTemplateStructure,  // Получение структуры полей шаблона
			AjaxHook::SaveTaskBoilerplate,   // Сохранение типового условия
			AjaxHook::GetTaskBoilerplate,    // Получение типового условия
		];
		
		// Регистрация хуков для создания заданий
		foreach ( $taskCreationHooks as $hook ) {
			add_action(
				$hook->action(),
				[ $this->taskCreationCallbacks, $hook->callbackMethod() ]
			);
		}
		
		// Регистрация хуков для управления шаблонами
		foreach ( $templateManagerHooks as $hook ) {
			add_action(
				$hook->action(),
				[ $this->templateManagerCallbacks, $hook->callbackMethod() ]
			);
		}
	}
}