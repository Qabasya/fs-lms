<?php

namespace Inc\Controllers;

use Inc\Callbacks\TaskCreationCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;

/**
 * Class TaskCreationController
 *
 * Контроллер для инициализации функционала создания заданий.
 *
 * Отвечает за регистрацию AJAX-обработчиков для создания заданий,
 * получения типов заданий и сохранения привязки шаблонов.
 * Делегирует выполнение бизнес-логики классу TaskCreationCallbacks.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class TaskCreationController extends BaseController implements ServiceInterface {
	/**
	 * Коллбеки для обработки AJAX-запросов создания заданий.
	 *
	 * @var TaskCreationCallbacks
	 */
	private TaskCreationCallbacks $callbacks;

	/**
	 * Конструктор.
	 *
	 * @param TaskCreationCallbacks $callbacks Коллбеки для обработки AJAX-запросов
	 */
	public function __construct( TaskCreationCallbacks $callbacks ) {
		parent::__construct();
		$this->callbacks = $callbacks;
	}

	/**
	 * Регистрирует компоненты контроллера.
	 *
	 * Вызывается из Init.php при инициализации плагина.
	 * Регистрирует AJAX-обработчики для:
	 * - получения списка типов заданий (ajaxGetTypes)
	 * - создания нового задания (ajaxCreateTask)
	 * - сохранения привязки шаблона к типу задания (ajaxSaveTemplateAssignment)
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация AJAX-обработчика для получения типов заданий
		add_action( 'wp_ajax_fs_get_task_types', [ $this->callbacks, 'ajaxGetTypes' ] );

		// Регистрация AJAX-обработчика для создания нового задания
		add_action( 'wp_ajax_fs_create_task_action', [ $this->callbacks, 'ajaxCreateTask' ] );

		// Регистрация AJAX-обработчика для сохранения привязки шаблона к типу задания
		add_action( 'wp_ajax_save_template_assignment', [ $this->callbacks, 'ajaxSaveTemplateAssignment' ] );
	}
}