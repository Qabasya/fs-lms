<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\LinkField;
use Inc\MetaBoxes\Fields\TextareaField;

class FileTaskTemplate extends BaseTemplate {

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
			]

		];
	}

	public function get_id(): string {
		return 'file_task';
	}

	public function get_name(): string {
		return 'Задание с файлом';
	}
}