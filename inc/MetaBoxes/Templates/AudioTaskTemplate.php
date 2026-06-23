<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\AudioField;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\InputField;

/**
 * Class AudioTaskTemplate
 *
 * Шаблон задания «Аудио-плеер + текстовый ответ».
 * Проверка — регистронезависимое сравнение строк (как Standard).
 *
 * @package Inc\MetaBoxes\Templates
 */
class AudioTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_audio' => array(
				'label'  => 'Аудиофайл',
				'object' => new AudioField(),
			),
			'task_answer' => array(
				'label'  => 'Правильный ответ',
				'object' => new InputField(),
			),
		);
	}

	public function get_id(): string {
		return 'audio_task';
	}

	public function get_name(): string {
		return 'Задание с аудио';
	}
}
