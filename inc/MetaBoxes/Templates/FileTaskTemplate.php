<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\LinkField;
use Inc\MetaBoxes\Fields\ConditionField;

/**
 * Class FileTaskTemplate
 *
 * Шаблон метабокса для задания с прикреплённым файлом.
 * Содержит поля: условие задания, правильный ответ и ссылка на файл.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class FileTaskTemplate extends BaseTemplate {
	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - task_condition: условие задания (textarea)
	 * - task_answer: правильный ответ (input)
	 * - file: ссылка на файл задания (link field)
	 */
	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),     // Многострочный текст
			),
			'task_answer'    => array(
				'label'  => 'Правильный ответ',
				'object' => new InputField(),        // Текстовое поле
			),
			'file'           => array(
				'label'  => 'Файл задания',
				'object' => new LinkField(),         // Поле для ссылки на файл
			),
		);
	}

	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'file_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Задание с файлом';
	}
}
