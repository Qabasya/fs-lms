<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Templates\BaseTemplate;

/**
 * Class TaskTextSolution
 *
 * Шаблон метабокса для задания с текстовым решением.
 *
 * @package Inc\MetaBoxes\Templates
 */
class TaskTextSolution extends BaseTemplate {

	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - task_condition: условие задания (textarea с HTML-контентом)
	 * - task_answer: правильный ответ (однострочное текстовое поле)
	 * - task_text: решение или пояснение (textarea с HTML-контентом)
	 */
	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				// ConditionField — многострочное поле с поддержкой HTML (TinyMCE)
				'object' => new ConditionField(),
			),
			'task_answer'    => array(
				'label'  => 'Правильный ответ',
				// InputField — стандартное однострочное текстовое поле
				'object' => new InputField(),
			),
			'task_text'      => array(
				'label'  => 'Решение',
				// ConditionField — для развёрнутого объяснения решения
				'object' => new ConditionField(),
			),
		);
	}

	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'text_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Задание с текстовым решением';
	}
}
