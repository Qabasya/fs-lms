<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\PairsField;

/**
 * Class MatchingTaskTemplate
 *
 * Шаблон задания «Сопоставление пар» (drag-n-drop).
 *
 * @package Inc\MetaBoxes\Templates
 */
class MatchingTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_pairs' => array(
				'label'  => 'Пары для сопоставления',
				'object' => new PairsField(),
			),
		);
	}

	public function get_id(): string {
		return 'matching_task';
	}

	public function get_name(): string {
		return 'Сопоставление';
	}
}
