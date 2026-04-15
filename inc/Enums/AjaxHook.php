<?php

namespace Inc\Enums;

/**
 * AJAX-хуки плагина с автоматической генерацией имён.
 * * Наследует : string для работы как Backed Enum.
 */
enum AjaxHook: string {
	// === SubjectController (Предметы) ===
	case StoreSubject   = 'StoreSubject';
	case UpdateSubject  = 'UpdateSubject';
	case DeleteSubject  = 'DeleteSubject';

	// === SubjectController (Таксономии) ===
	case StoreTaxonomy  = 'StoreTaxonomy';
	case UpdateTaxonomy = 'UpdateTaxonomy';
	case DeleteTaxonomy = 'DeleteTaxonomy';

	// === BoilerplateController (Полноценный CRUD редактор) ===
	case SaveBoilerplate   = 'Save'; // Вызовет ajaxSave в BoilerplateCallbacks
	case DeleteBoilerplate = 'DeleteBoilerplate';

	// === TaskCreationController (Создание задач в модалке) ===
	case GetTaskTypes        = 'GetTypes';        // ajaxGetTypes
	case GetTaskBoilerplates = 'GetBoilerplates'; // ajaxGetBoilerplates
	case CreateTask          = 'CreateTask';      // ajaxCreateTask

	// === TemplateManager (Быстрые настройки и структура) ===
	case GetTemplateStructure    = 'GetTemplateStructure';
	case SaveTaskBoilerplate     = 'SaveTaskBoilerplate'; // ajaxSaveTaskBoilerplate
	case GetTaskBoilerplate      = 'GetTaskBoilerplate';
	case UpdateTermTemplate      = 'UpdateTermTemplate';
	case SaveTemplateAssignment  = 'SaveTemplateAssignment';

	// ============================ ГЕНЕРАЦИЯ ИМЁН ============================ //

	/**
	 * WordPress hook (для add_action).
	 * @return string Например: "wp_ajax_store_subject"
	 */
	public function action(): string {
		return 'wp_ajax_' . $this->toSnakeCase();
	}

	/**
	 * Hook для неавторизованных пользователей.
	 */
	public function noPrivAction(): string {
		return 'wp_ajax_nopriv_' . $this->toSnakeCase();
	}

	/**
	 * Имя экшена для JS (для отправки в параметре action).
	 * @return string Например: "store_subject"
	 */
	public function jsAction(): string {
		return $this->toSnakeCase();
	}

	/**
	 * Имя метода в PHP коллбеке.
	 * @return string Например: "ajaxStoreSubject"
	 */
	public function callbackMethod(): string {
		return 'ajax' . $this->value;
	}

	/**
	 * Конвертация PascalCase (Enum) в snake_case (WP/JS).
	 */
	private function toSnakeCase(): string {
		return strtolower( preg_replace( '/(?<!^)[A-Z]/', '_$0', $this->name ) );
	}

	/**
	 * Массив для передачи в JS (через wp_localize_script).
	 */
	public static function toJsArray(): array {
		$actions = [];
		foreach ( self::cases() as $case ) {
			$actions[ lcfirst( $case->name ) ] = $case->jsAction();
		}
		return $actions;
	}
}