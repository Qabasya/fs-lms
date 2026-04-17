<?php

namespace Inc\DTO;

use Inc\DTO\SubjectDTO;

/**
 * Class SubjectViewDTO
 *
 * Data Transfer Object для передачи данных в шаблон страницы предмета.
 * Объединяет все необходимые данные для отображения страницы управления предметом.
 *
 * @package Inc\DTO
 */
class SubjectViewDTO {
	/**
	 * Конструктор DTO.
	 *
	 * @param string $subject_key Ключ предмета (slug)
	 * @param SubjectDTO $subject_data DTO с данными предмета
	 * @param array<int, TaskTypeDTO> $task_types Массив DTO типов заданий
	 * @param array<string, string> $all_templates Массив доступных шаблонов [id => name]
	 * @param string $tasks_url URL списка заданий
	 * @param string $articles_url URL списка статей
	 * @param string $protected_tax Слаг защищённой таксономии (номера заданий)
	 * @param TaxonomyDataDTO[] $taxonomies Массив DTO кастомных таксономий
	 */
	public function __construct(
		public readonly string $subject_key,
		public readonly SubjectDTO $subject_data,
		public readonly array $task_types,
		public readonly array $all_templates,
		public readonly string $tasks_url,
		public readonly string $articles_url,
		public readonly string $protected_tax,
		/** @var \Inc\DTO\TaxonomyDataDTO[] */
		public readonly array $taxonomies,
		public readonly ?PostsListTableDTO $tasks_table = null,
		public readonly ?PostsListTableDTO $articles_table = null,
	) {
	}
}