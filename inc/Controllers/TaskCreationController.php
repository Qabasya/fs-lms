<?php

namespace Inc\Controllers;

use Inc\Callbacks\TaskCreationCallbacks;
use Inc\Callbacks\TemplateManagerCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class TaskCreationController
 *
 * Контроллер для инициализации функционала создания и настройки заданий.
 *
 * Отвечает за регистрацию AJAX-обработчиков для:
 * - получения типов заданий
 * - создания новых заданий
 * - получения структуры шаблона
 * - сохранения и получения типовых условий (boilerplate)
 *
 * Делегирует выполнение бизнес-логики классам TaskCreationCallbacks и TemplateManagerCallbacks.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class TaskCreationController extends BaseController implements ServiceInterface {
	/**
	 * Конструктор.
	 *
	 * @param TaskCreationCallbacks $taskCreationCallbacks Коллбеки для создания заданий
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
	 * Регистрирует все AJAX-обработчики, связанные с созданием и настройкой заданий.
	 *
	 * Обработчики:
	 * - get_task_types — получение списка типов заданий
	 * - create_task — создание нового задания
	 * - get_template_structure — получение структуры полей шаблона
	 * - save_task_boilerplate — сохранение типового условия
	 * - get_task_boilerplate — получение типового условия
	 *
	 * @return void
	 */
	public function register(): void {

		// Выводим HTML модалки в самом низу страницы
		add_action( 'admin_footer', function () {
			$screen = get_current_screen();
			// Показываем только если мы в админке на типе поста, заканчивающемся на _tasks
			if ( $screen && str_contains( $screen->post_type, '_tasks' ) ) {
				include_once $this->plugin_path . 'templates/components/modals/task-creation-modal.php';
			}
		} );
		// --- Типы заданий -> tasks.js & TaskCreationCallbacks ---

		// Регистрация AJAX-обработчика для получения типов заданий
		add_action( 'wp_ajax_get_task_types', [ $this->taskCreationCallbacks, 'ajaxGetTypes' ] );

		add_action( 'wp_ajax_get_task_boilerplates', [ $this->taskCreationCallbacks, 'ajaxGetBoilerplates' ] );

		// Регистрация AJAX-обработчика для создания нового задания
		add_action( 'wp_ajax_create_task', [ $this->taskCreationCallbacks, 'ajaxCreateTask' ] );

		// --- Шаблоны -> tasks.js & TemplateManagerCallbacks- --

		// Регистрация AJAX-обработчика для получения структуры полей шаблона
		add_action( 'wp_ajax_get_template_structure', [ $this->templateManagerCallbacks, 'ajaxGetTemplateStructure' ] );

		// Регистрация AJAX-обработчика для сохранения типового условия (boilerplate)
		add_action( 'wp_ajax_save_task_boilerplate', [ $this->templateManagerCallbacks, 'ajaxSaveBoilerplate' ] );

		// Регистрация AJAX-обработчика для получения типового условия (boilerplate)
		add_action( 'wp_ajax_get_task_boilerplate', [ $this->templateManagerCallbacks, 'ajaxGetBoilerplate' ] );

		//// Регистрация AJAX-обработчика для сохранения привязки шаблона к типу задания
		//add_action('wp_ajax_save_template_assignment', [ $this->templateManagerCallbacks, 'ajaxSaveTemplateAssignment' ]);
	}
}