<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\TextareaField;

class CodeTaskTemplate extends BaseTemplate {

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
			'task_code' => [
				'label'  => 'Листинг кода (Python)',
				'object' => new CodeField()
			]

		];
	}

	public function get_id(): string {
		return 'programming_task';
	}

	public function get_name(): string {
		return 'Задание с кодом';
	}
}