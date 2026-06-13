<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Enrollment\ExpulsionCallbacks;
use Inc\Core\BaseController;
use Inc\Enums\AjaxHook;

/**
 * Class ExpulsionController
 *
 * Контроллер для отчисления студентов и экспорта записей об отчислении.
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для отчисления и экспорта.
 * 2. **Рендеринг модального окна** — вывод HTML-шаблона модального окна отчисления в админ-футере.
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику ExpulsionCallbacks.
 *
 * ### Маршруты административной панели:
 *
 * - AJAX-действия доступны на страницах управления студентами.
 * - Модальное окно отображается в футере админ-панели.
 */
class ExpulsionController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param ExpulsionCallbacks $callbacks Коллбеки для операций отчисления
	 */
	public function __construct(
		private readonly ExpulsionCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * Регистрирует все компоненты контроллера.
	 *
	 * @return void
	 */
	public function register(): void {
		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();

		// 'admin_footer' — хук для вывода HTML в подвале админ-панели
		add_action( 'admin_footer', array( $this, 'renderModal' ) );
	}

	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array
	 */
	protected function ajaxActions(): array {
		return array(
			// Отчисление студента (смена статуса зачисления на Expelled)
			array( AjaxHook::ExpelStudent, $this->callbacks ),
			// Экспорт записи об отчислении в CSV
			array( AjaxHook::ExportExpelledRecord, $this->callbacks ),
		);
	}

	/**
	 * Выводит HTML модального окна отчисления в футере админ-панели.
	 *
	 * @return void
	 */
	public function renderModal(): void {
		// path() — метод BaseController, возвращает полный путь к файлу
		include $this->path( 'templates/admin/components/modals/expel-modal.php' );
	}
}