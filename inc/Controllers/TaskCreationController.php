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
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * Перенести логику регистрации скриптов и хуков из TaskCreationCallbacks сюда,
 * чтобы TaskCreationCallbacks занимался только обработкой AJAX.
 * Сейчас контроллер выполняет только роль "прокси" для регистрации в Init.php,
 * вся бизнес-логика находится в TaskCreationCallbacks.
 */
class TaskCreationController extends BaseController implements ServiceInterface {
	/**
	 * Коллбеки для обработки AJAX-запросов создания заданий.
	 *
	 * @var TaskCreationCallbacks
	 */
	protected TaskCreationCallbacks $callbacks;

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
	 * На данный момент вся логика вынесена в TaskCreationCallbacks,
	 * который самостоятельно регистрирует AJAX-обработчики в конструкторе.
	 *
	 * @return void
	 */
	public function register(): void {

	}

}