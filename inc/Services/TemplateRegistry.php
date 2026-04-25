<?php

declare( strict_types=1 );

namespace Inc\Services;

use Inc\Enums\TaskTemplate;
use Inc\MetaBoxes\Templates\BaseTemplate;

class TemplateRegistry {

	/**
	 * @var array<string, BaseTemplate> Список активных объектов шаблонов
	 */
	private array $templates = array();

	public function __construct() {
		$this->initTemplates();
	}

	/**
	 * Инициализирует шаблоны на основе Enum и внешних фильтров.
	 */
	private function initTemplates(): void {
		$builtin = array();

		// Динамически создаем объекты на основе кейсов Enum
		foreach ( TaskTemplate::cases() as $case ) {
			$class = $case->class();
			if ( class_exists( $class ) ) {
				$builtin[] = new $class();
			}
		}

		/**
		 * Позволяет сторонним плагинам регистрировать свои классы шаблонов.
		 * Ожидается массив объектов, наследующих BaseTemplate.
		 */
		$candidates = apply_filters( 'fs_lms_register_templates', $builtin );

		foreach ( $candidates as $template ) {
			if ( $template instanceof BaseTemplate ) {
				$this->templates[ $template->get_id() ] = $template;
			}
		}
	}

	/**
	 * Возвращает объект шаблона по его ID.
	 * * @param string $template_id ID шаблона (например, 'standard_task')
	 *
	 * @return BaseTemplate|null
	 */
	public function get( string $template_id ): ?BaseTemplate {
		// Если запрашиваемый ID есть в реестре — отдаем его
		if ( isset( $this->templates[ $template_id ] ) ) {
			return $this->templates[ $template_id ];
		}

		// Если нет — пытаемся отдать стандартный через Enum-фолбек
		$fallbackId = TaskTemplate::fromDatabase( $template_id )->value;

		return $this->templates[ $fallbackId ] ?? null;
	}

	/**
	 * Возвращает все зарегистрированные шаблоны.
	 * * @return BaseTemplate[]
	 */
	public function getAll(): array {
		return $this->templates;
	}
}
