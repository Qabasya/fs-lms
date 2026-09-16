<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\GapTextField;
use Inc\MetaBoxes\Fields\ConditionField;

/**
 * Class FillTaskTemplate
 *
 * Шаблон задания «Пропуски в тексте».
 * Условие встроено в разметку текста: [[слово]] / [[вар1|вар2]].
 * Перемешивание не применяется — порядок пропусков задан текстом.
 *
 * @package Inc\MetaBoxes\Templates
 */
class FillTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_gap_text' => array(
				'label'  => 'Текст с пропусками',
				'object' => new GapTextField(),
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
		return 'fill_task';
	}

	public function get_name(): string {
		return 'Пропуски в тексте';
	}
}
