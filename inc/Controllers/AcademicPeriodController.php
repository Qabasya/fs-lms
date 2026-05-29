<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\AcademicPeriodCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class AcademicPeriodController
 *
 * Контроллер для управления учебными периодами (годами/семестрами).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для сохранения и удаления учебных периодов.
 *
 * ### Архитектурная роль:
 *
 * Наследует абстрактный класс AjaxController, который реализует регистрацию AJAX-хуков
 * через метод ajaxActions(). Делегирует бизнес-логику AcademicPeriodCallbacks.
 */
class AcademicPeriodController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param AcademicPeriodCallbacks $academic_period_callbacks Коллбеки для операций с периодами
	 */
	public function __construct(
		private readonly AcademicPeriodCallbacks $academic_period_callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Регистрация AJAX-обработчиков (унаследовано из AjaxController)
		parent::register();
	}
	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array Массив действий, каждое с хуком и объектом-коллбеком
	 */
	protected function ajaxActions(): array {
		return array(
			// Сохранение учебного периода (создание/обновление)
			array( AjaxHook::SaveAcademicPeriod, $this->academic_period_callbacks ),
			// Удаление учебного периода по ID
			array( AjaxHook::DeleteAcademicPeriod, $this->academic_period_callbacks ),
		);
	}
}