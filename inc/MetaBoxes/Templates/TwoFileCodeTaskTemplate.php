<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\LinkField;
use Inc\MetaBoxes\Fields\TextareaField;

class TwoFileCodeTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = [
			'task_condition' => [
				'label' => 'Условие задания',
				'object' => new TextareaField()
			],
			'task_answer' => [
				'label'  => 'Правильный ответ',
				'object' => new InputField()
			],
			'file_primary' => [
				'label'  => 'Основной файл (A)',
				'object' => new LinkField()
			],
			'file_secondary' => [
				'label'  => 'Дополнительный файл (B)',
				'object' => new LinkField()
			],
			'task_code' => [
				'label'  => 'Листинг кода (Python)',
				'object' => new CodeField()
			]

		];
	}

	public function get_id(): string {
		return 'two_file_code_task';
	}

	public function get_name(): string {
		return 'Задание с двумя файлами и кодом';
	}
}