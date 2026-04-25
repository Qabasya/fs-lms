<?php

namespace Inc\Controllers;

use Inc\Callbacks\BoilerplateCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BoilerplateController
 *
 * Контроллер управления типовыми условиями заданий (boilerplate).
 *
 * Отвечает за:
 * - Регистрацию AJAX-хуков (делегирует обработку в BoilerplateCallbacks)
 * - Отображение страницы списка и редактора boilerplate
 *
 * Вызов страницы идёт из AdminCallbacks::boilerplatePage.
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 */
class BoilerplateController extends BaseController implements ServiceInterface {
	use TemplateRenderer;

	/**
	 * Конструктор.
	 *

	 * @param BoilerplateCallbacks  $boilerplate_callbacks Коллбеки для AJAX-операций
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly BoilerplateCallbacks $boilerplate_callbacks,
	) {
		parent::__construct();
	}

	// ============================ РЕГИСТРАЦИЯ ============================ //

	/**
	 * Точка входа контроллера — регистрирует AJAX-хуки.
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация AJAX-обработчиков
		$this->registerAjaxHooks();
	}
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Регистрирует AJAX-обработчики для операций с boilerplate.
	 *
	 * @return void
	 */
	private function registerAjaxHooks(): void {
		// Список хуков для регистрации
		$hooks = array(
			AjaxHook::SaveBoilerplate,   // Сохранение boilerplate
			AjaxHook::DeleteBoilerplate, // Удаление boilerplate
		);

		// Регистрация каждого хука
		foreach ( $hooks as $hook ) {
			add_action(
				$hook->action(),                                    // Название AJAX-действия
				array( $this->boilerplate_callbacks, $hook->callbackMethod() ) // Коллбек для обработки
			);
		}
	}
}
