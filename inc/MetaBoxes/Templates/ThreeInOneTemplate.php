<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\InputField;
use Inc\MetaBoxes\Fields\ConditionField;

/*
 * Это класс для 19-21 заданий ЕГЭ по информатике
 * Если в будущем будут подобные задания, то переопределим названия
 *
 * Здесь три задания в одном, на сайте отображаются вместе,
 * а в экзаменационной работе будем разделять
 */

class ThreeInOneTemplate extends BaseTemplate {
	
	
	public function __construct() {
		$this->fields = [
			// 1. Задание 19
			'task_19_condition' => [ 'label' => 'Условие к №19', 'object' => new ConditionField() ],
			'task_19_answer'    => [ 'label' => 'Ответ №19', 'object' => new InputField() ],
			
			// 2. Задание 20
			'task_20_condition' => [ 'label' => 'Условие к №20', 'object' => new ConditionField() ],
			'task_20_answer'    => [ 'label' => 'Ответ №20', 'object' => new InputField() ],
			
			// 3. Задание 21
			'task_21_condition' => [ 'label' => 'Условие к №21', 'object' => new ConditionField() ],
			'task_21_answer'    => [ 'label' => 'Ответ №21', 'object' => new InputField() ],
			
			// 4. Программное решение (мб потом разделить на 3?)
			'task_code'         => [
				'label'  => 'Общий код решения (Python)',
				'object' => new CodeField()
			]
		];
	}
	
	public function get_id(): string {
		return 'triple_task';
	}
	
	public function get_name(): string {
		return 'Связка 19-21 (Теория игр)';
	}
	
	/**
	 * Переопределяем родительский render, сохраняя логику получения данных
	 * TODO: вынести отсюда html и стили
	 */
	public function render( \WP_Post $post ): void {
		// 1. Получаем данные точно так же, как в BaseTemplate
		$values = get_post_meta( $post->ID, 'fs_lms_meta', true );
		if ( ! is_array( $values ) ) {
			$values = [];
		}
		
		echo '<style>
            .triple-section { border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0; background: #fcfcfc; }
            .triple-section h4 { margin-top: 0; color: #2271b1; text-transform: uppercase; font-size: 13px; border-bottom: 1px solid #eee; padding-bottom: 5px; }
            .common-header { background: #e7f3ff; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        </style>';
		
		echo '<div class="fs-lms-template-wrapper" id="template-' . esc_attr( $this->get_id() ) . '">';
		
		// 2. Рендерим группы вручную, вызывая метод поля напрямую
		
		// --- БЛОК 19 ---
		echo '<div class="triple-section"><h4>Задание №19</h4>';
		$this->render_single_field( 'task_19_condition', $post, $values );
		$this->render_single_field( 'task_19_answer', $post, $values );
		echo '</div>';
		
		// --- БЛОК 20 ---
		echo '<div class="triple-section"><h4>Задание №20</h4>';
		$this->render_single_field( 'task_20_condition', $post, $values );
		$this->render_single_field( 'task_20_answer', $post, $values );
		echo '</div>';
		
		// --- БЛОК 21 ---
		echo '<div class="triple-section"><h4>Задание №21</h4>';
		$this->render_single_field( 'task_21_condition', $post, $values );
		$this->render_single_field( 'task_21_answer', $post, $values );
		echo '</div>';
		
		// --- КОД ---
		echo '<h3>Программное решение</h3>';
		$this->render_single_field( 'task_code', $post, $values );
		
		echo '</div>';
	}
	
	/**
	 * Повторяет логику вызова из BaseTemplate для одного конкретного поля
	 */
	private function render_single_field( string $field_id, \WP_Post $post, array $values ): void {
		if ( ! isset( $this->fields[ $field_id ] ) ) {
			return;
		}
		
		$config = $this->fields[ $field_id ];
		$label  = $config['label'];
		$field  = $config['object'];
		$value  = $values[ $field_id ] ?? '';
		
		$field->render( $post, $field_id, $label, $value );
	}
}