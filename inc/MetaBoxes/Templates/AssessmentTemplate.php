<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\AssessmentKindField;
use Inc\MetaBoxes\Fields\InputField;
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
				'object' => new InputField(),
			),
			'max_attempts'       => array(
				'label'  => 'Максимум попыток (0 = без ограничений)',
				'object' => new InputField(),
			),
			'pass_score'         => array(
				'label'  => 'Проходной балл (0 = без порога)',
				'object' => new InputField(),
			),
			'score_map'          => array(
				'label'  => 'Таблица перевода баллов',
				'object' => new ScoreMapField(),
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
		return 'Контрольная / ЕГЭ';
	}
}
