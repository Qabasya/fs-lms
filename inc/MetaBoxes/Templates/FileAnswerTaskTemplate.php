<?php

namespace Inc\MetaBoxes\Templates;

use Inc\MetaBoxes\Fields\CodeField;
use Inc\MetaBoxes\Fields\ConditionField;
use Inc\MetaBoxes\Fields\CriteriaField;
use Inc\MetaBoxes\Fields\FileAttachmentsField;

/**
 * Class FileAnswerTaskTemplate
 *
 * Шаблон «Развёрнутый ответ» (Эпик 13, D16): номера ЕГЭ/ОГЭ, проверяемые
 * человеком — фото решения (математика ч.2), презентация/документ (ОГЭ инф. №13),
 * программа .py (ОГЭ инф. №15). Ученик прикладывает файлы и/или пишет текст.
 *
 * Автопроверки НЕТ намеренно: чекер в TaskCheckerRegistry не регистрируется →
 * ответ уходит в pending, оценивает преподаватель (при наличии критериев —
 * по критериям, сумма = балл задачи, D17).
 *
 * Поля решения (`solution_text`, `task_code`) ученику не отдаются — оба ключа
 * вырезаются ExamPayloadFilter; материалы (`task_materials`) отдаются ссылками.
 *
 * @package Inc\MetaBoxes\Templates
 * @extends BaseTemplate
 */
class FileAnswerTaskTemplate extends BaseTemplate {

	public function __construct() {
		$this->fields = array(
			'task_condition' => array(
				'label'  => 'Условие задания',
				'object' => new ConditionField(),        // Rich-text, инлайн-картинки через медиакнопку
			),
			'task_materials' => array(
				'label'  => 'Материалы задания (видны ученику)',
				'object' => new FileAttachmentsField(),  // Файлы-исходники: данные, картинки, шаблоны
			),
			'solution_text'  => array(
				'label'    => 'Решение для проверяющего (текст, ученику не видно)',
				'object'   => new ConditionField(),
				'optional' => true,                       // #9: эталон заполняется по желанию
			),
			'task_code'      => array(
				'label'    => 'Решение для проверяющего (код, ученику не видно)',
				'object'   => new CodeField(),
				'optional' => true,                       // #9: эталон заполняется по желанию
			),
			'task_criteria'  => array(
				'label'  => 'Критерии оценивания (опционально)',
				'object' => new CriteriaField(),         // D17: сумма сырых баллов, без весов
			),
		);
	}

	/**
	 * Возвращает уникальный идентификатор шаблона.
	 *
	 * @return string Уникальный ID шаблона
	 */
	public function get_id(): string {
		return 'file_answer_task';
	}

	/**
	 * Возвращает человекочитаемое название шаблона.
	 *
	 * @return string Название шаблона, отображаемое в интерфейсе
	 */
	public function get_name(): string {
		return 'Развёрнутый ответ (файл, ручная проверка)';
	}
}
