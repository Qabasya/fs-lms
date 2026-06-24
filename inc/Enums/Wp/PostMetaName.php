<?php

declare( strict_types=1 );

namespace Inc\Enums\Wp;

/**
 * Имена post meta полей плагина.
 *
 * Централизует строковые ключи мета-данных WordPress, чтобы не дублировать
 * их в контроллерах, менеджерах и шаблонах метабоксов.
 *
 * @package Inc\Enums
 */
enum PostMetaName: string {
	/**
	 * Мета-поле с ID шаблона задания.
	 */
	case TemplateType = 'fs_lms_template_type';

	/**
	 * Основной массив мета-данных задания.
	 */
	case Meta = 'fs_lms_meta';

	/**
	 * ID оригинального поста, из которого создан форк.
	 */
	case ForkedFrom = 'fs_lms_forked_from';

	/**
	 * ID группы, для которой создан форк урока (групповой форк).
	 * Форки с этим значением скрыты из общей библиотеки предмета.
	 */
	case ForkedForGroup = 'fs_lms_forked_for_group';

	/** Плоский ключ типа работы — дублирует fs_lms_meta['work_type'] для фильтрации в list table. */
	case WorkType = 'fs_lms_work_type';

	/** Плоский ключ вида контрольной — дублирует fs_lms_meta['kind'] для фильтрации в list table. */
	case AssessmentKind = 'fs_lms_assessment_kind';
}
