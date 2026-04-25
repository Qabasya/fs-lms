<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\TaskTemplate;
use Inc\MetaBoxes\Templates\BaseTemplate;

/**
 * Class TemplateRegistry
 *
 * Реестр (регистр) шаблонов метабоксов заданий.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Регистрация шаблонов** — сбор всех доступных шаблонов (встроенные + из фильтра).
 * 2. **Инициализация встроенных шаблонов** — динамическое создание объектов на основе Enum TaskTemplate.
 * 3. **Получение шаблона по ID** — возврат объекта шаблона с фолбеком на стандартный.
 * 4. **Получение всех шаблонов** — возврат полного списка зарегистрированных шаблонов.
 *
 * ### Архитектурная роль:
 *
 * Является хранилищем объектов шаблонов для всего плагина.
 * Использует паттерн Registry и поддерживает расширение через фильтр fs_lms_register_templates.
 */
class TemplateRegistry {
	
	/**
	 * @var array<string, BaseTemplate> Список активных объектов шаблонов (ID → объект)
	 */
	private array $templates = array();
	
	public function __construct() {
		$this->initTemplates();
	}
	
	/**
	 * Инициализирует шаблоны на основе Enum и внешних фильтров.
	 *
	 * @return void
	 */
	private function initTemplates(): void {
		$builtin = array();
		
		// TaskTemplate::cases() — возвращает все кейсы enum (PHP 8.1)
		foreach ( TaskTemplate::cases() as $case ) {
			$class = $case->class();      // Получение имени класса из enum
			if ( class_exists( $class ) ) {
				$builtin[] = new $class(); // Создание объекта шаблона
			}
		}
		
		/**
		 * apply_filters() — позволяет сторонним разработчикам добавлять свои шаблоны
		 *
		 * @param array $candidates Массив объектов, наследующих BaseTemplate
		 */
		$candidates = apply_filters( 'fs_lms_register_templates', $builtin );
		
		foreach ( $candidates as $template ) {
			// Проверка, что объект является экземпляром BaseTemplate
			if ( $template instanceof BaseTemplate ) {
				// get_id() — возвращает уникальный идентификатор шаблона
				$this->templates[ $template->get_id() ] = $template;
			}
		}
	}
	
	/**
	 * Возвращает объект шаблона по его ID.
	 *
	 * @param string $template_id ID шаблона (например, 'standard_task')
	 *
	 * @return BaseTemplate|null
	 */
	public function get( string $template_id ): ?BaseTemplate {
		// Если запрашиваемый ID есть в реестре — отдаём его
		if ( isset( $this->templates[ $template_id ] ) ) {
			return $this->templates[ $template_id ];
		}
		
		// Фолбек: преобразуем ID через enum и берём стандартный шаблон
		$fallbackId = TaskTemplate::fromDatabase( $template_id )->value;
		
		return $this->templates[ $fallbackId ] ?? null;
	}
	
	/**
	 * Возвращает все зарегистрированные шаблоны.
	 *
	 * @return BaseTemplate[]
	 */
	public function getAll(): array {
		return $this->templates;
	}
}