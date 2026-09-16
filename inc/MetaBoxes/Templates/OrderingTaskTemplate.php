<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\OrderItemsField;

/**
 * Class OrderingTaskTemplate
 *
 * Шаблон задания «Сортировка» (drag-n-drop).
 * Элементы всегда перемешиваются на фронте — автор вводит правильный порядок.
 *
 * @package Inc\MetaBoxes\Templates
 */
class OrderingTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_order_items' => array(
				'label'  => 'Элементы в правильном порядке',
				'object' => new OrderItemsField(),
			),
			// Авторское решение: видит преподаватель в плеере («Показать решение»)
			// и посетитель страницы задания в тренажёре. Заполняется по желанию.
			'task_text' => array(
				'label'    => 'Решение',
				'object'   => new ConditionField(),
				'optional' => true,
			),
		);
	}

	public function get_id(): string {
		return 'ordering_task';
	}

	public function get_name(): string {
		return 'Сортировка';
	}
}
