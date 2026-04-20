<?php

namespace Inc\Enums;

/**
 * Enum AjaxHook
 *
 * AJAX-хуки плагина с автоматической генерацией имён.
 *
 * Каждый кейс хранит имя метода в PascalCase (например, 'StoreSubject').
 * Преобразование в WordPress-формат (wp_ajax_store_subject) и snake_case для JS
 * происходит автоматически через методы action(), jsAction() и callbackMethod().
 *
 * @package Inc\Enums
 */
enum AjaxHook: string {
	// ==================== SubjectController (Предметы) ====================
	case StoreSubject  = 'StoreSubject';
	case UpdateSubject = 'UpdateSubject';
	case DeleteSubject = 'DeleteSubject';
	case ExportSubject = 'ExportSubject';
	case ImportSubject = 'ImportSubject';

	// ==================== SubjectController (Таксономии) ====================
	case StoreTaxonomy  = 'StoreTaxonomy';
	case UpdateTaxonomy = 'UpdateTaxonomy';
	case DeleteTaxonomy = 'DeleteTaxonomy';

	// ==================== BoilerplateController (Полноценный CRUD редактор) ====================
	case SaveBoilerplate   = 'SaveBoilerplate';
	case DeleteBoilerplate = 'DeleteBoilerplate';

	// ==================== TaskCreationController (Создание задач в модалке) ====================
	case GetTaskTypes        = 'GetTaskTypes';
	case GetTaskBoilerplates = 'GetTaskBoilerplates';
	case CreateTask          = 'CreateTask';

	// ==================== SubjectController (Таблица постов) ====================
	case GetPostsTable = 'GetPostsTable';

	// ==================== TemplateManager (Быстрые настройки и структура) ====================
	case GetTemplateStructure   = 'GetTemplateStructure';
	case SaveTaskBoilerplate    = 'SaveTaskBoilerplate';
	case GetTaskBoilerplate     = 'GetBoilerplate';
	case UpdateTermTemplate     = 'UpdateTermTemplate';
	case SaveTemplateAssignment = 'SaveTemplateAssignment';

	// ============================ ГЕНЕРАЦИЯ ИМЁН ============================ //

	/**
	 * Возвращает полное имя WordPress AJAX-действия для авторизованных пользователей.
	 *
	 * @return string Например: "wp_ajax_store_subject"
	 */
	public function action(): string {
		return 'wp_ajax_' . $this->toSnakeCase();
	}

	/**
	 * Возвращает полное имя WordPress AJAX-действия для неавторизованных пользователей.
	 *
	 * @return string Например: "wp_ajax_nopriv_store_subject"
	 */
	public function noPrivAction(): string {
		return 'wp_ajax_nopriv_' . $this->toSnakeCase();
	}

	/**
	 * Возвращает имя экшена для использования в JavaScript (параметр action).
	 *
	 * @return string Например: "store_subject"
	 */
	public function jsAction(): string {
		return $this->toSnakeCase();
	}

	/**
	 * Возвращает имя метода в PHP-коллбеке.
	 *
	 * @return string Например: "ajaxStoreSubject"
	 */
	public function callbackMethod(): string {
		return 'ajax' . $this->value;
	}

	/**
	 * Конвертирует PascalCase в snake_case.
	 *
	 * @return string Например: "StoreSubject" → "store_subject"
	 */
	private function toSnakeCase(): string {
		return strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $this->name ) );
	}

	/**
	 * Возвращает массив всех хуков для передачи в JavaScript через wp_localize_script.
	 *
	 * @return array<string, string> Массив [lcfirst(case) => jsAction]
	 */
	public static function toJsArray(): array {
		$actions = array();
		foreach ( self::cases() as $case ) {
			$actions[ lcfirst( $case->name ) ] = $case->jsAction();
		}

		return $actions;
	}
}
