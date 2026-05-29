<?php

namespace Inc\Controllers;

use Inc\Callbacks\BoilerplateCallbacks;
use Inc\Enums\AjaxHook;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BoilerplateController
 *
 * Контроллер управления типовыми условиями заданий (boilerplate).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для сохранения и удаления boilerplate.
 *
 * ### Архитектурная роль:
 *
 * Наследует абстрактный класс AjaxController, который реализует регистрацию AJAX-хуков
 * через метод ajaxActions(). Делегирует бизнес-логику BoilerplateCallbacks.
 */
class BoilerplateController extends AjaxController {
	use TemplateRenderer;  // Трейт с методом render() (может использоваться в будущем)

	/**
	 * Конструктор контроллера.
	 *
	 * @param BoilerplateCallbacks $boilerplate_callbacks Коллбеки для операций с boilerplate
	 */
	public function __construct(
		private readonly BoilerplateCallbacks $boilerplate_callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}

	/**
	 * Возвращает список AJAX-действий для регистрации (только для авторизованных пользователей).
	 *
	 * @return array Массив действий, каждое с хуком и объектом-коллбеком
	 */
	protected function ajaxActions(): array {
		return array(
			// Сохранение boilerplate (создание/обновление)
			array( AjaxHook::SaveBoilerplate, $this->boilerplate_callbacks ),
			// Удаление boilerplate по UID
			array( AjaxHook::DeleteBoilerplate, $this->boilerplate_callbacks ),
		);
	}
}