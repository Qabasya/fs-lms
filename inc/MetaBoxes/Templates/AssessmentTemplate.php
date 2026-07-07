<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\AssessmentKindField;
use Inc\MetaBoxes\Fields\EditorField;
use Inc\MetaBoxes\Fields\NumberInputField;
use Inc\MetaBoxes\Fields\ScoreMapField;

/**
 * Class AssessmentTemplate
 *
 * Форма метабокса контрольной / ЕГЭ / компьютерного ЕГЭ.
 *
 * @package Inc\MetaBoxes\Templates
 */
class AssessmentTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'kind'               => array(
				'label'  => 'Тип экзамена',
				'object' => new AssessmentKindField(),
			),
			'time_limit_minutes' => array(
				'label'  => 'Ограничение времени (минут, 0 = без лимита)',
				'object' => new NumberInputField(),
			),
			'max_attempts'       => array(
				'label'  => 'Максимум попыток (0 = без ограничений)',
				'object' => new NumberInputField(),
			),
			'pass_score'         => array(
				'label'  => 'Проходной балл (0 = без порога)',
				'object' => new NumberInputField(),
			),
			'score_map'          => array(
				'label'  => 'Таблица перевода баллов',
				'object' => new ScoreMapField(),
			),
			'intro_html'         => array(
				'label'  => 'Описание перед началом (показывается на стартовом экране)',
				'object' => new EditorField(),
			),
		);
	}

	public function get_fields(): array {
		return $this->fields;
	}

	public function get_id(): string {
		return 'assessment';
	}

	public function get_name(): string {
		return 'Экзамен';
	}
}
