<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\TextareaField;

/**
 * Шаблон: Стандартное задание (Условие + Ответ)
 */

class StandardTaskTemplate extends BaseTemplate {


	public function __construct() {
		// Наполняем шаблон полями
		$this->fields = [
			'task_condition' => [
				'label'  => 'Условие задания',
				'object' => new TextareaField()
			],
			'task_answer' => [
				'label'  => 'Правильный ответ',
				'object' => new InputField()
			]
		];
	}

	public function get_id(): string {
		return 'stabdart_task';
	}

	public function get_name(): string {
		return 'Стандартное задание';
	}
}