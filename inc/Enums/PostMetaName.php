<?php

declare( strict_types=1 );

namespace Inc\Enums;

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
}
