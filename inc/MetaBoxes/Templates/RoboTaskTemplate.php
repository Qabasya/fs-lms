<?php

declare( strict_types=1 );

namespace Inc\MetaBoxes\Templates;

use Inc\Enums\Subject\TemplateCategory;
use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\CriteriaField;
use Inc\MetaBoxes\Fields\FileAttachmentsField;

/**
 * Class RoboTaskTemplate
 *
 * Шаблон «Задание Робо» (Робототехника): поля для ответа нет — ученик отправляет
 * преподавателю код программы (скетч Arduino и т. п.), проверка только ручная.
 *
 * Как у «Развёрнутого ответа» ({@see FileAnswerTaskTemplate}), чекер в
 * TaskCheckerRegistry не регистрируется: ответ уходит в pending, балл ставит
 * преподаватель (по критериям, если они заданы). Ответ ученика хранится так же,
 * как код у Code/FileCode, — JSON `{"text":"","code":"…"}`, поэтому экран проверки
 * показывает его блоком «Код ученика» ({@see \Inc\Enums\Subject\TaskTemplate::hasCodeField()}).
 *
 * Эталонный код ученику не отдаётся: он виден только преподавателю
 * («Показать решение» в плеере, {@see \Inc\Services\Task\TaskSolutionService}).
 *
 * @package Inc\MetaBoxes\Templates
 */
class RoboTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'common_condition' => $this->commonConditionField(),
			'task_condition'   => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),
			),
			'task_materials'   => array(
				'label'    => 'Материалы задания (схемы, библиотеки — видны ученику)',
				'object'   => new FileAttachmentsField(),
				'optional' => true,
			),
			'task_code'        => array(
				'label'    => 'Эталонный код (ученику не видно)',
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
		return 'robo_task';
	}

	public function get_name(): string {
		return 'Задание Робо (код преподавателю)';
	}

	public function get_category(): TemplateCategory {
		return TemplateCategory::Code;
	}
}
