<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\TaskRefField;
use Inc\MetaBoxes\Fields\TextareaField;
use Inc\MetaBoxes\Fields\WorkTypeField;

/**
 * Class WorkTemplate
 *
 * Форма метабокса работы: тип + инструкция + упорядоченные ссылки на задания.
 *
 * @package Inc\MetaBoxes\Templates
 */
class WorkTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'work_type' => array(
				'label'  => 'Тип работы',
				'object' => new WorkTypeField(),
			),
			'instructions' => array(
				'label'  => 'Инструкция (опционально)',
				'object' => new TextareaField(),
			),
			'task_ids' => array(
				'label'  => 'Задания',
				'object' => new TaskRefField(),
			),
		);
	}

	public function get_id(): string {
		return 'work';
	}

	public function get_name(): string {
		return 'Работа';
	}
}
