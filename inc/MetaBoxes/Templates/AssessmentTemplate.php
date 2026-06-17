<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\AssessmentTaskRefField;
use Inc\MetaBoxes\Fields\InputField;

/**
 * Class AssessmentTemplate
 *
 * Форма метабокса контрольной / экзамена.
 *
 * @package Inc\MetaBoxes\Templates
 */
class AssessmentTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
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
			'shuffle'            => array(
				'label'  => 'Перемешивать задания (1 = да, 0 = нет)',
				'object' => new InputField(),
			),
			'task_ids'           => array(
				'label'  => 'Задания контрольной',
				'object' => new AssessmentTaskRefField(),
			),
		);
	}

	public function get_id(): string {
		return 'assessment';
	}

	public function get_name(): string {
		return 'Контрольная';
	}
}
