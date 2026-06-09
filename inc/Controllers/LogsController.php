<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\LogsCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class LogsController
 *
 * Контроллер для экспорта логов (аудит и доступ к PII).
 *
 * @package Inc\Controllers
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация AJAX-обработчиков** — подключение коллбеков для экспорта логов.
 *
 * ### Архитектурная роль:
 *
 * Наследует AjaxController для регистрации AJAX-хуков.
 * Делегирует бизнес-логику LogsCallbacks.
 *
 * ### Экспортируемые логи:
 *
 * - **AuditLog** — системный журнал действий пользователей
 * - **PiiAccessLog** — журнал доступа к персональным данным
 *
 * Экспорт доступен только пользователям с правами Capability::Admin.
 */
class LogsController extends AjaxController {

	/**
	 * Конструктор контроллера.
	 *
	 * @param LogsCallbacks $logsCallbacks Коллбеки для операций с логами
	 */
	public function __construct(
		private readonly LogsCallbacks $logsCallbacks,
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
	}

	/**
	 * Возвращает список AJAX-действий для регистрации.
	 *
	 * @return array
	 */
	protected function ajaxActions(): array {
		return array(
			// Экспорт журнала аудита в CSV
			array( AjaxHook::ExportAuditLog, $this->logsCallbacks ),
			// Экспорт журнала доступа к PII в CSV
			array( AjaxHook::ExportPiiLog,   $this->logsCallbacks ),
		);
	}
}