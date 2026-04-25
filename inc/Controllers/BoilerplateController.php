<?php

namespace Inc\Controllers;

use Inc\Callbacks\BoilerplateCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;
use Inc\Repositories\SubjectRepository;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class BoilerplateController
 *
 * Контроллер управления типовыми условиями заданий (boilerplate).
 *
 * @package Inc\Controllers
 * @implements ServiceInterface
 *
 * ### Основные обязанности класса:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключает коллбеки для сохранения и удаления
 *    типовых условий (boilerplate), которые вызываются через административный AJAX.
 *
 * 2. **Делегирование бизнес-логики** — непосредственное выполнение CRUD-операций
 *    перекладывается на BoilerplateCallbacks, а данный контроллер только регистрирует хуки.
 *
 * 3. **Точка входа для ServiceInterface** — реализует метод register(), который
 *    вызывается при инициализации плагина для подключения всех необходимых хуков.
 *
 * ### Архитектурная роль:
 *
 * Контроллер выступает в роли "организатора" на уровне регистрации хуков WordPress,
 * но не занимается отрисовкой страниц (это делает BoilerplatePageController).
 * Такое разделение позволяет:
 * - Чётко разграничить регистрацию AJAX и отображение UI
 * - Упростить тестирование, вынеся бизнес-логику в отдельные классы-коллбеки
 *
 * ### Взаимодействие с другими компонентами:
 *
 * - **BoilerplateCallbacks** — непосредственные обработчики AJAX-запросов
 * - **AjaxHook (enum)** — хранит названия хуков и методы коллбеков
 * - **ServiceInterface** — гарантирует наличие метода register() для единообразной инициализации
 */
class BoilerplateController extends BaseController implements ServiceInterface {
	use TemplateRenderer;
	
	/**
	 * Конструктор контроллера.
	 *
	 * Внедряет зависимости через конструктор с использованием property promotion (PHP 8.4).
	 * Родительский конструктор BaseController инициализирует общие свойства плагина.
	 *
	 * @param SubjectRepository    $subjects               Репозиторий для работы с предметами.
	 *                                                     Используется для валидации существования предмета
	 *                                                     при необходимости (в будущих доработках).
	 *
	 * @param BoilerplateCallbacks $boilerplate_callbacks  Объект с методами-обработчиками AJAX-запросов.
	 *                                                     Содержит методы ajaxSaveBoilerplate() и ajaxDeleteBoilerplate().
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly BoilerplateCallbacks $boilerplate_callbacks,
	) {
		parent::__construct();
	}
	
	// ============================ РЕГИСТРАЦИЯ ============================ //
	
	/**
	 * Точка входа контроллера, вызываемая при инициализации плагина.
	 *
	 * Реализует метод интерфейса ServiceInterface.
	 * Выполняет регистрацию всех AJAX-хуков, необходимых для работы
	 * с типовыми условиями (boilerplate) в административной панели.
	 *
	 * @return void
	 */
	public function register(): void {
		// Делегирование регистрации AJAX-обработчиков в приватный метод
		$this->registerAjaxHooks();
	}
	
	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //
	
	/**
	 * Регистрирует AJAX-обработчики для операций с boilerplate.
	 *
	 * Для каждого хука из списка подключается соответствующий метод
	 * из BoilerplateCallbacks. Хуки регистрируются как для авторизованных
	 * пользователей (wp_ajax_*), так и для публичной части при необходимости
	 * (но в данном случае все операции требуют админ-доступа).
	 *
	 * Используется enum AjaxHook, который централизованно хранит:
	 * - Название действия (action) для WordPress
	 * - Имя метода в классе-коллбеке
	 *
	 * @return void
	 */
	private function registerAjaxHooks(): void {
		// Список AJAX-хуков, которые необходимо зарегистрировать
		$hooks = array(
			AjaxHook::SaveBoilerplate,   // Обработчик сохранения (создание/обновление) boilerplate
			AjaxHook::DeleteBoilerplate, // Обработчик удаления boilerplate по UID
		);
		
		// Перебор всех хуков и подключение соответствующих коллбеков
		foreach ( $hooks as $hook ) {
			/**
			 * add_action() для административного AJAX WordPress.
			 *
			 * @param string $hook->action()       Имя действия (например, 'fs_lms_save_boilerplate')
			 * @param array  $callback              Массив [объект_коллбека, имя_метода]
			 *
			 * Метод callbackMethod() возвращает строку с именем метода,
			 * например 'ajaxSaveBoilerplate' для AjaxHook::SaveBoilerplate
			 */
			add_action(
				$hook->action(),                                    // Название AJAX-действия
				array( $this->boilerplate_callbacks, $hook->callbackMethod() ) // Обработчик
			);
		}
	}
}