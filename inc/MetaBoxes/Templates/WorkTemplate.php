<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\WorkTypeField;

/**
 * Class WorkTemplate
 *
 * Метабокс работы: только тип работы. Описание/инструкция — нативный редактор
 * (`post_content`). Состав заданий — степ-лист «только задачи» (`item_ids` через AJAX).
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
		);
	}

	public function get_id(): string {
		return 'work';
	}

	public function get_name(): string {
		return 'Работа';
	}
}
