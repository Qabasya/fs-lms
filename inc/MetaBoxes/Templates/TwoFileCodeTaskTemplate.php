<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\LinkField;
use Inc\MetaBoxes\Fields\TextareaField;

/**
 * Class TwoFileCodeTaskTemplate
 *
 * Шаблон метабокса для задания с двумя прикреплёнными файлами и программным кодом.
 * Содержит поля: условие задания, правильный ответ, два файла и листинг кода.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class TwoFileCodeTaskTemplate extends BaseTemplate {
	/**
	 * Конструктор.
	 *
	 * Инициализирует набор полей шаблона:
	 * - task_condition: условие задания (textarea)
	 * - task_answer: правильный ответ (input)
	 * - file_primary: ссылка на основной файл (link field)
	 * - file_secondary: ссылка на дополнительный файл (link field)
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
			'file_primary'   => [
				'label'  => 'Основной файл (A)',
				'object' => new LinkField()         // Поле для ссылки на файл A
			],
			'file_secondary' => [
				'label'  => 'Дополнительный файл (B)',
				'object' => new LinkField()         // Поле для ссылки на файл B
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
		return 'two_file_code_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Задание с двумя файлами и кодом';
	}
}