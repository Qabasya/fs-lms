<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\LogsCallbacks;
use Inc\Enums\Wp\AjaxHook;

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
			// Доменный экспорт
			array( AjaxHook::ExportGroups,           $this->logsCallbacks ),
			array( AjaxHook::ExportStudents,         $this->logsCallbacks ),
			array( AjaxHook::ExportParents,          $this->logsCallbacks ),
			array( AjaxHook::ExportArchive,          $this->logsCallbacks ),
			// Журналы
			array( AjaxHook::ExportEntityAuditLog,   $this->logsCallbacks ),
			array( AjaxHook::ExportEnrollmentLog,    $this->logsCallbacks ),
			array( AjaxHook::ExportAuditLog,         $this->logsCallbacks ),
			array( AjaxHook::ExportPiiLog,            $this->logsCallbacks ),
			array( AjaxHook::ExportExportLog,         $this->logsCallbacks ),
			array( AjaxHook::ExportDataChangeLog,     $this->logsCallbacks ),
			array( AjaxHook::ExportConsentChangeLog,  $this->logsCallbacks ),
			array( AjaxHook::ExportEmailLog,          $this->logsCallbacks ),
			array( AjaxHook::ExportAuthLog,           $this->logsCallbacks ),
		);
	}
}