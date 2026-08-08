<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\InputField;

/*
 * Это класс для 19-21 заданий ЕГЭ по информатике
 * Если в будущем будут подобные задания, то переопределим названия
 *
 * Здесь три задания в одном, на сайте отображаются вместе,
 * а в экзаменационной работе будем разделять
 */

class ThreeInOneTemplate extends BaseTemplate {


	public function __construct() {
		$this->fields = array(
			// 1. Задание 19
			'task_19_condition' => array(
				'label'  => 'Условие к №19',
				'object' => new ConditionField(),
			),
			'task_19_answer'    => array(
				'label'  => 'Ответ №19',
				'object' => new InputField(),
			),

			// 2. Задание 20
			'task_20_condition' => array(
				'label'  => 'Условие к №20',
				'object' => new ConditionField(),
			),
			'task_20_answer'    => array(
				'label'  => 'Ответ №20',
				'object' => new InputField(),
			),

			// 3. Задание 21
			'task_21_condition' => array(
				'label'  => 'Условие к №21',
				'object' => new ConditionField(),
			),
			'task_21_answer'    => array(
				'label'  => 'Ответ №21',
				'object' => new InputField(),
			),

			// 4. Программное решение (мб потом разделить на 3?)
			'task_code'         => array(
				'label'  => 'Общий код решения (Python)',
				'object' => new CodeField(),
			),
		);
	}

	public function get_id(): string {
		return 'triple_task';
	}

	public function get_name(): string {
		return 'Связка 19-21 (Теория игр)';
	}

	/**
	 * В режиме ЕГЭ-экзамена разворачивается в три отдельно оцениваемых задания (T7.12).
	 * Каждое задание — условие + поле ответа + собственный балл из task_points.
	 * task_code в экзамене не отображается: AttemptTaskViewBuilder отдаёт только
	 * условие/материалы/номер, а меты задания на страницу вообще не передаёт.
	 *
	 * @return array{key: string, condition_field: string, answer_field: string}[]
	 */
	public function expandsForExam(): array {
		return [
			[ 'key' => '19', 'condition_field' => 'task_19_condition', 'answer_field' => 'task_19_answer' ],
			[ 'key' => '20', 'condition_field' => 'task_20_condition', 'answer_field' => 'task_20_answer' ],
			[ 'key' => '21', 'condition_field' => 'task_21_condition', 'answer_field' => 'task_21_answer' ],
		];
	}

	/**
	 * Переопределяем родительский render: три блока-связки + общий код.
	 * Разметка — партиал templates/admin/metaboxes/triple-task-fields.php,
	 * стили — admin-SCSS (_metabox-triple.scss); класс только отдаёт данные.
	 */
	public function render( \WP_Post $post, array $values ): void {
		$template = $this;
		require FS_LMS_PATH . 'templates/admin/metaboxes/triple-task-fields.php';
	}

	/**
	 * Рендер одного поля шаблона (зовётся партиалом разметки).
	 */
	public function renderField( string $field_id, \WP_Post $post, array $values ): void {
		if ( ! isset( $this->fields[ $field_id ] ) ) {
			return;
		}

		$config = $this->fields[ $field_id ];
		$config['object']->render( $post, $field_id, $config['label'], $values[ $field_id ] ?? '' );
	}
}
