<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class AjaxController
 *
 * Абстрактный базовый класс для контроллеров, регистрирующих AJAX-хуки.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-хуков** — автоматическая регистрация хуков для авторизованных
 *    и неавторизованных пользователей.
 * 2. **Разделение прав** — отдельные методы для authenticated (ajaxActions) и public (publicAjaxActions) хуков.
 *
 * ### Архитектурная роль:
 *
 * Реализует паттерн Template Method: дочерние классы переопределяют методы
 * ajaxActions() и/или publicAjaxActions(), а родительский класс register()
 * вызывает registerAjaxHooks() для регистрации всех хуков.
 *
 * ### Принцип работы:
 *
 * - **ajaxActions()** — регистрирует хуки только для авторизованных пользователей (wp_ajax_{action})
 * - **publicAjaxActions()** — регистрирует хуки для всех (wp_ajax_{action} + wp_ajax_nopriv_{action})
 *
 * Каждый элемент массива — это пара [AjaxHook, объект_коллбека].
 * Имя метода коллбека определяется через AjaxHook::callbackMethod().
 */
abstract class AjaxController extends BaseController implements ServiceInterface {

	/**
	 * Конструктор контроллера.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Объявляет AJAX-действия, доступные только авторизованным пользователям.
	 *
	 * @return list<array{AjaxHook, object}> Список пар [хук, объект-коллбек]
	 */
	protected function ajaxActions(): array {
		return array();
	}

	/**
	 * Объявляет публичные AJAX-действия (доступны без авторизации).
	 *
	 * @return list<array{AjaxHook, object}> Список пар [хук, объект-коллбек]
	 */
	protected function publicAjaxActions(): array {
		return array();
	}

	/**
	 * Регистрирует все AJAX-хуки.
	 * Метод помечен как final — запрещён для переопределения.
	 *
	 * @return void
	 */
	final protected function registerAjaxHooks(): void {
		// Регистрация хуков для авторизованных пользователей
		foreach ( $this->ajaxActions() as [ $hook, $callback ] ) {
			// add_action() с хуком action() (wp_ajax_{value})
			add_action( $hook->action(), array( $callback, $hook->callbackMethod() ) );
		}

		// Регистрация публичных хуков (для всех пользователей)
		foreach ( $this->publicAjaxActions() as [ $hook, $callback ] ) {
			// Хук для авторизованных пользователей (wp_ajax_{action})
			add_action( $hook->action(), array( $callback, $hook->callbackMethod() ) );
			// Хук для неавторизованных пользователей (wp_ajax_nopriv_{action})
			add_action( $hook->noPrivAction(), array( $callback, $hook->callbackMethod() ) );
		}
	}

	/**
	 * Регистрирует компоненты контроллера.
	 * Вызывается при инициализации плагина (ServiceInterface).
	 *
	 * @return void
	 */
	public function register(): void {
		$this->registerAjaxHooks();
	}
}