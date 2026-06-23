<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\OptionsField;

/**
 * Class ChoiceTaskTemplate
 *
 * Шаблон задания «Выбор варианта ответа».
 * Поддерживает режимы radio (один правильный) и checkbox (несколько).
 *
 * @package Inc\MetaBoxes\Templates
 */
class ChoiceTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_options' => array(
				'label'  => 'Варианты ответа',
				'object' => new OptionsField(),
			),
		);
	}

	public function get_id(): string {
		return 'choice_task';
	}

	public function get_name(): string {
		return 'Выбор варианта ответа';
	}
}
