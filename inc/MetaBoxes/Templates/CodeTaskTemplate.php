<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\Enums\Subject\TemplateCategory;
use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\TextareaField;
use Inc\MetaBoxes\Fields\ConditionField;

/**
 * Class CodeTaskTemplate
 *
 * Шаблон метабокса для задания с программным кодом.
 * Содержит поля: условие задания, правильный ответ и листинг кода.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class CodeTaskTemplate extends BaseTemplate {
	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - task_condition: условие задания (textarea)
	 * - task_answer: правильный ответ (многострочный текст)
	 * - task_code: листинг кода (code field)
	 */
	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),     // Многострочный текст
			),
			'task_answer'    => array(
				'label'  => 'Правильный ответ',
				'object' => new TextareaField(), // Многострочный текст (переносы сохраняются)
			),
			'task_code'      => array(
				'label'  => 'Листинг кода (Python)',
				'object' => new CodeField(),         // Поле для ввода кода
			),
			// Авторское решение: видит преподаватель в плеере («Показать решение»)
			// и посетитель страницы задания в тренажёре. Заполняется по желанию.
			'task_text'      => array(
				'label'    => 'Решение',
				'object'   => new ConditionField(),
				'optional' => true,
			),
		);
	}

	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'code_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Задание с кодом';
	}

	public function get_category(): TemplateCategory {
		return TemplateCategory::Code;
	}
}
