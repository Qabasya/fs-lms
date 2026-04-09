<?php

namespace Inc\Controllers;

use Inc\Callbacks\TaskCreationCallbacks;
use Inc\Callbacks\TaskTypeCallbacks;
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
 * - сохранения привязки шаблонов к типам заданий
 * - получения структуры шаблона
 * - сохранения и получения типовых условий (boilerplate)
 *
 * Делегирует выполнение бизнес-логики классам TaskCreationCallbacks и TaskTypeCallbacks.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class TaskCreationController extends BaseController implements ServiceInterface
{
	/**
	 * Коллбеки для обработки AJAX-запросов создания заданий.
	 *
	 * @var TaskCreationCallbacks
	 */
	private TaskCreationCallbacks $taskCreationCallbacks;

	/**
	 * Коллбеки для обработки AJAX-запросов управления типами заданий.
	 *
	 * @var TaskTypeCallbacks
	 */
	private TaskTypeCallbacks $taskTypeCallbacks;

	/**
	 * Конструктор.
	 *
	 * @param TaskCreationCallbacks $taskCreationCallbacks Коллбеки для создания заданий
	 * @param TaskTypeCallbacks     $taskTypeCallbacks     Коллбеки для управления типами заданий
	 */
	public function __construct(
		TaskCreationCallbacks $taskCreationCallbacks,
		TaskTypeCallbacks $taskTypeCallbacks
	) {
		parent::__construct();
		$this->taskCreationCallbacks = $taskCreationCallbacks;
		$this->taskTypeCallbacks     = $taskTypeCallbacks;
	}

	/**
	 * Регистрирует компоненты контроллера.
	 *
	 * Вызывается из Init.php при инициализации плагина.
	 * Регистрирует все AJAX-обработчики, связанные с созданием и настройкой заданий.
	 *
	 * Обработчики:
	 * - fs_get_task_types — получение списка типов заданий
	 * - fs_create_task_action — создание нового задания
	 * - save_template_assignment — сохранение привязки шаблона к типу задания
	 * - fs_get_template_structure — получение структуры полей шаблона
	 * - fs_save_task_type_boilerplate — сохранение типового условия
	 * - fs_get_task_type_boilerplate — получение типового условия
	 *
	 * @return void
	 */
	public function register(): void
	{
		// Регистрация AJAX-обработчика для получения типов заданий
		add_action('wp_ajax_fs_get_task_types', [$this->taskCreationCallbacks, 'ajaxGetTypes']);

		// Регистрация AJAX-обработчика для создания нового задания
		add_action('wp_ajax_fs_create_task_action', [$this->taskCreationCallbacks, 'ajaxCreateTask']);

		// Регистрация AJAX-обработчика для сохранения привязки шаблона к типу задания
		add_action('wp_ajax_save_template_assignment', [ $this->taskCreationCallbacks, 'ajaxSaveTemplateAssignment' ]);

		// Регистрация AJAX-обработчика для получения структуры полей шаблона
		add_action('wp_ajax_fs_get_template_structure', [$this->taskCreationCallbacks, 'ajaxGetTemplateStructure']);

		// Регистрация AJAX-обработчика для сохранения типового условия (boilerplate)
		add_action('wp_ajax_fs_save_task_type_boilerplate', [$this->taskTypeCallbacks, 'ajaxSaveBoilerplate']);

		// Регистрация AJAX-обработчика для получения типового условия (boilerplate)
		add_action('wp_ajax_fs_get_task_type_boilerplate', [$this->taskTypeCallbacks, 'ajaxGetBoilerplate']);
	}
}