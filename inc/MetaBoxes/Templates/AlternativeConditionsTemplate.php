<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\CriteriaField;
use Inc\MetaBoxes\Fields\FileAttachmentsField;

/**
 * Class AlternativeConditionsTemplate
 *
 * Шаблон «Два условия на выбор» (ОГЭ информатика №13): ученик решает ОДИН из
 * двух вариантов задания (13.1 — презентация, 13.2 — текстовый документ с
 * таблицей), ответ — файл, проверка только ручная (как {@see FileAnswerTaskTemplate},
 * но с двумя условиями вместо одного — по образцу {@see ThreeInOneTemplate}, где
 * тоже несколько условий на одном посте).
 *
 * ### Почему один пост с двумя условиями, а не два поста (13.1/13.2)
 *
 * Раньше 13.1/13.2 были двумя отдельными постами банка `fs_lms_problems` с
 * ручными номерами «13.1»/«13.2» — таксономия `{key}_task_number` не может
 * хранить дробные номера. Из-за этого `EgeCompletenessChecker::validate()`
 * (строгая биекция задание↔терм 1:1) никогда не давала `isStrictlyComplete()`:
 * терм «13» вечно «missing», оба поста — вечно «orphans», `actualCount` (17)
 * никогда не равен `expectedCount` (16). Решение (2026-08-18, согласовано с
 * пользователем): один физический пост на позицию «13», с двумя условиями
 * внутри — таксономия/ручной номер этого поста задаются один раз, как у
 * любого другого банковского задания ОГЭ 13-16 ({@see \Inc\Modules\EgeComputer\Config\OgeCriteriaConfig}
 * резолвит рубрику по номеру «13» — единая рубрика показывает оба варианта
 * критериев, проверяющий выбирает подходящий по тому, что фактически прислал
 * ученик).
 *
 * Автопроверки нет намеренно — чекер в TaskCheckerRegistry не регистрируется,
 * ответ уходит в ручную проверку, как у {@see FileAnswerTaskTemplate}.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class AlternativeConditionsTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition_1' => array(
				'label'  => 'Условие (вариант 1)',
				'object' => new ConditionField(),
			),
			'task_condition_2' => array(
				'label'  => 'Условие (вариант 2)',
				'object' => new ConditionField(),
			),
			'task_materials'   => array(
				'label'  => 'Материалы задания (видны ученику)',
				'object' => new FileAttachmentsField(),
			),
			'solution_text'    => array(
				'label'    => 'Решение для проверяющего (текст, ученику не видно)',
				'object'   => new ConditionField(),
				'optional' => true,
			),
			'task_code'        => array(
				'label'    => 'Решение для проверяющего (код, ученику не видно)',
				'object'   => new CodeField(),
				'optional' => true,
			),
			'task_criteria'    => array(
				'label'  => 'Критерии оценивания (опционально)',
				'object' => new CriteriaField(),
			),
		);
	}

	public function get_id(): string {
		return 'alternative_conditions_task';
	}

	public function get_name(): string {
		return 'Два условия на выбор (ОГЭ №13)';
	}
}
