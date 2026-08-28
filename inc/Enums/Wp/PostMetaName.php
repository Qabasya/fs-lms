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

	/**
	 * Краткое описание статьи (карточка в учебнике, тег description).
	 * Не длиннее {@see \Inc\Controllers\Article\ArticleMetaBoxController::MAX_LENGTH} символов.
	 */
	case ArticleDescription = 'fs_lms_article_description';

	/**
	 * Слаг статьи заморожен первой публикацией — автогенерация его больше не трогает.
	 * Ставится при переходе в publish, см. {@see \Inc\Callbacks\Article\SlugCallbacks}.
	 */
	case ArticleSlugLocked = 'fs_lms_article_slug_locked';

	/**
	 * На дочернем задании связки (19/20/21) — ID родительского поста triple_task.
	 * См. {@see \Inc\Services\Task\TaskBundleService}.
	 */
	case TaskBundleParentId = 'fs_lms_task_bundle_parent_id';

	/**
	 * На родительском посте связки — ID трёх дочерних заданий в порядке 19/20/21.
	 * См. {@see \Inc\Services\Task\TaskBundleService}.
	 */
	case TaskBundleChildIds = 'fs_lms_task_bundle_child_ids';

	/**
	 * На банковской задаче (fs_lms_problems) — ключ предмета, за которым автор
	 * закрепил задачу (необязательная пометка). Вместе с {@see BankTaskNumber}
	 * заменяет ручной ввод номера в конструкторе контрольной: канонический
	 * источник номера банковской задачи теперь сам пост, а не мета контрольной.
	 */
	case BankTaskSubject = 'fs_lms_bank_task_subject';

	/**
	 * На банковской задаче (fs_lms_problems) — номер позиции экзамена (совпадает
	 * с именем терма `{subject}_task_number` или синтетической позицией вроде
	 * «13» для ручной проверки ОГЭ). Имеет смысл только вместе с {@see BankTaskSubject}.
	 */
	case BankTaskNumber = 'fs_lms_bank_task_number';

	/**
	 * Номер записи в WXR-экспорте старой версии сайта — дедуп-ключ разового
	 * переноса. См. {@see \Inc\Services\Task\LegacyTaskImportService}.
	 */
	case LegacyImportNumber = 'fs_lms_legacy_import_number';
}
