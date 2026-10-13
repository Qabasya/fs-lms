<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\OptionsField;

/**
 * Class ChoiceTaskTemplate
 *
 * Шаблон задания «Выбор варианта ответа».
 * Поддерживает режимы radio (один правильный) и checkbox (несколько).
 *
 * @package Inc\MetaBoxes\Templates
 */
class ChoiceTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_options' => array(
				'label'  => 'Варианты ответа',
				'object' => new OptionsField(),
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

	/**
	 * Ответ здесь — отметки «верный» у вариантов: сами варианты остаются, отметки снимаются.
	 *
	 * @param array<string, mixed> $meta Мета задания
	 *
	 * @return array<string, mixed>
	 */
	public function stripAnswer( array $meta ): array {
		$options = $meta['task_options']['options'] ?? null;
		if ( ! is_array( $options ) ) {
			return $meta;
		}

		foreach ( $options as $i => $option ) {
			if ( is_array( $option ) ) {
				$meta['task_options']['options'][ $i ]['correct'] = false;
			}
		}

		return $meta;
	}

	public function get_id(): string {
		return 'choice_task';
	}

	public function get_name(): string {
		return 'Выбор варианта ответа';
	}
}
