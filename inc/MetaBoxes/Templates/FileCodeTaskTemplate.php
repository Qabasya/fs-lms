<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\LinkField;
use Inc\MetaBoxes\Fields\TextareaField;

class FileCodeTaskTemplate extends BaseTemplate {

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
			'file' => [
				'label'  => 'Файл задания',
				'object' => new LinkField()
			],
			'task_code' => [
				'label'  => 'Листинг кода (Python)',
				'object' => new CodeField()
			]

		];
	}

	public function get_id(): string {
		return 'file_code_task';
	}

	public function get_name(): string {
		return 'Задание с файлом и кодом';
	}
}