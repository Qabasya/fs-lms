<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\ConditionField;

/**
 * Class CommonConditionTemplate
 *
 * Шаблон метабокса для задания с общим (неизменяемым) условием.
 * Подходит для заданий, где есть фиксированная вступительная часть,
 * например, для 12 задания ЕГЭ по информатике.
 *
 * Содержит три поля:
 * - common_condition: базовое неизменяемое условие (textarea)
 * - task_condition: вариативная часть условия (textarea)
 * - task_answer: поле для ответа (input)
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class CommonConditionTemplate extends BaseTemplate {
	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - common_condition: базовое неизменяемое условие
	 * - task_condition: вариативное условие задания
	 * - task_answer: правильный ответ
	 */
	public function __construct() {
		$this->fields = [
			'common_condition' => [
				'label'  => 'Базовое условие:',
				'object' => new ConditionField()    // Неизменяемая часть условия
			],
			'task_condition'   => [
				'label'  => 'Вариативное условие:',
				'object' => new ConditionField()    // Изменяемая часть условия
			],
			'task_answer'      => [
				'label'  => 'Ответ',
				'object' => new InputField()       // Поле для ответа
			],
		];
	}
	
	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'common_standard_task';
	}
	
	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Стандартное задание с общим условием';
	}
}