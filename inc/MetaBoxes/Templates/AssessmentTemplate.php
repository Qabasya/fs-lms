<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\AssessmentKindField;
use Inc\MetaBoxes\Fields\AssessmentTaskRefField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\TextareaField;

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
			'scoring_policy'     => array(
				'label'  => 'Политика оценивания (highest / last / first)',
				'object' => new InputField(),
			),
			'task_ids'           => array(
				'label'  => 'Задания контрольной',
				'object' => new AssessmentTaskRefField(),
			),
			'score_map'          => array(
				'label'  => 'Таблица перевода первичный→вторичный (JSON, только для ЕГЭ; удобный ввод — T7.16)',
				'object' => new TextareaField(),
			),
		);
	}

	public function get_id(): string {
		return 'assessment';
	}

	public function get_name(): string {
		return 'Контрольная / ЕГЭ';
	}
}
