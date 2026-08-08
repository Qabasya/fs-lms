<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\TextareaField;
use Inc\MetaBoxes\Fields\ConditionField;

/**
 * Class StandardTaskTemplate
 *
 * Шаблон стандартного задания (условие + правильный ответ).
 * Базовый шаблон, используемый по умолчанию при создании заданий.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class StandardTaskTemplate extends BaseTemplate {
	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - task_condition: условие задания (textarea)
	 * - task_answer: правильный ответ (многострочный текст)
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
		);
	}

	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'standard_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Стандартное задание';
	}
}
