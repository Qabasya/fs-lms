<?php

namespace Inc\DTO;

use Inc\DTO\SubjectDTO;

/**
 * Class SubjectViewDTO
 *
 * Data Transfer Object для передачи данных в шаблон страницы предмета.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Агрегация данных** — объединяет все данные, необходимые для отображения страницы предмета.
 * 2. **Типобезопасность** — обеспечивает строгую типизацию передаваемых данных.
 *
 * ### Архитектурная роль:
 *
 * Используется в SubjectPageCallbacks для передачи данных из репозиториев и менеджеров
 * в шаблон страницы управления предметом (templates/admin/subject.php).
 */
readonly class SubjectViewDTO {
	
	/**
	 * Конструктор DTO.
	 *
	 * @param string                  $subject_key    Ключ предмета (slug), например 'math'
	 * @param SubjectDTO              $subject_data   DTO с данными предмета (ключ + название)
	 * @param array<int, TaskTypeDTO> $task_types     Массив DTO типов заданий (для выпадающего списка)
	 * @param array<string, string>   $all_templates  Массив доступных шаблонов [id => name]
	 * @param string                  $tasks_url      URL списка заданий (edit.php?post_type=...)
	 * @param string                  $articles_url   URL списка статей (edit.php?post_type=...)
	 * @param string                  $protected_tax  Слаг защищённой таксономии (номера заданий)
	 * @param TaxonomyDataDTO[]       $taxonomies     Массив DTO кастомных таксономий предмета
	 * @param PostsListTableDTO|null  $tasks_table    DTO таблицы заданий (для вкладки tab-2)
	 * @param PostsListTableDTO|null  $articles_table DTO таблицы статей (для вкладки tab-3)
	 */
	public function __construct(
		public string $subject_key,
		public SubjectDTO $subject_data,
		public array $task_types,
		public array $all_templates,
		public string $tasks_url,
		public string $articles_url,
		public string $protected_tax,
		public array $taxonomies,
		public ?PostsListTableDTO $tasks_table = null,
		public ?PostsListTableDTO $articles_table = null,
	) {
	}
}