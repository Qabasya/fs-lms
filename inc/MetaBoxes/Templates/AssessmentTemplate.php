<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\AssessmentKindField;
use Inc\MetaBoxes\Fields\CheckboxField;
use Inc\MetaBoxes\Fields\EditorField;
use Inc\MetaBoxes\Fields\NumberInputField;

/**
 * Class AssessmentTemplate
 *
 * Форма метабокса контрольной / ЕГЭ / компьютерного ЕГЭ.
 *
 * @package Inc\MetaBoxes\Templates
 */
class AssessmentTemplate extends BaseTemplate {

	/**
	 * WP filter: дополнительные поля метабокса станции ЕГЭ (`id => {label, object}`).
	 * Ядро о модулях не знает — модуль (например, публичные экзамены) сам добавляет
	 * свои поля, и они сохраняются и рисуются наравне с родными. Выключенный
	 * модуль поля не добавляет — их нет ни в форме, ни при сохранении.
	 */
	public const FIELDS_FILTER = 'fs_lms_assessment_template_fields';

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
			'intro_html'         => array(
				'label'  => 'Описание перед началом (показывается на стартовом экране)',
				'object' => new EditorField(),
			),
			'hide_intro'         => array(
				'label'  => 'Скрыть приветственные экраны (сразу к первому заданию)',
				'object' => new CheckboxField(),
			),
		);
	}

	public function get_fields(): array {
		return array_merge( $this->fields, $this->extraFields() );
	}

	/**
	 * Поля метабокса «Экраны и доступ» станции ЕГЭ: собственный флажок ядра плюс
	 * поля, добавленные модулями через {@see self::FIELDS_FILTER}. Модульные идут первыми
	 * (публичность/год), флажок экранов — после них: он зависит от публичности.
	 *
	 * @return string[]
	 */
	public function stationFieldIds(): array {
		return array_merge( array_keys( $this->extraFields() ), array( 'hide_intro' ) );
	}

	/**
	 * @return array<string, array{label: string, object: object}>
	 */
	private function extraFields(): array {
		$extra = apply_filters( self::FIELDS_FILTER, array() );

		return is_array( $extra ) ? $extra : array();
	}

	public function get_id(): string {
		return 'assessment';
	}

	public function get_name(): string {
		return 'Экзамен';
	}
}
