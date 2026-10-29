<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\DragFillField;

/**
 * Class DragFillTaskTemplate
 *
 * Шаблон задания «Перетаскивание пропусков» (опрос): условие отдельно, формула со слотами и плитки банка.
 * Слоты одной группы взаимозаменяемы — порядок неважен (`V = h*w*i` = `V = i*w*h`).
 *
 * @package Inc\MetaBoxes\Templates
 */
class DragFillTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_drag_fill' => array(
				'label'  => 'Формула и плитки',
				'object' => new DragFillField(),
			),
			// Авторское решение: видит преподаватель в плеере («Показать решение»).
			'task_text' => array(
				'label'    => 'Решение',
				'object'   => new ConditionField(),
				'optional' => true,
			),
		);
	}

	public function get_id(): string {
		return 'drag_fill_task';
	}

	public function get_name(): string {
		return 'Перетаскивание пропусков';
	}
}
