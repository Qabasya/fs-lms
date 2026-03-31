<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\LinkField;
use Inc\MetaBoxes\Fields\TextareaField;

/**
 * Class FileCodeTaskTemplate
 *
 * Шаблон метабокса для задания с прикреплённым файлом и программным кодом.
 * Содержит поля: условие задания, правильный ответ, ссылка на файл и листинг кода.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class FileCodeTaskTemplate extends BaseTemplate {
	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - task_condition: условие задания (textarea)
	 * - task_answer: правильный ответ (input)
	 * - file: ссылка на файл задания (link field)
	 * - task_code: листинг кода (code field)
	 */
	public function __construct() {
		$this->fields = [
			'task_condition' => [
				'label'  => 'Условие задания',
				'object' => new TextareaField()     // Многострочный текст
			],
			'task_answer'    => [
				'label'  => 'Правильный ответ',
				'object' => new InputField()        // Текстовое поле
			],
			'file'           => [
				'label'  => 'Файл задания',
				'object' => new LinkField()         // Поле для ссылки на файл
			],
			'task_code'      => [
				'label'  => 'Листинг кода (Python)',
				'object' => new CodeField()         // Поле для ввода кода
			]
		];
	}

	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'file_code_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Задание с файлом и кодом';
	}
}