<?php

namespace Inc\DTO;
use Inc\DTO\SubjectDTO;
/**
 * Класс для передачи данных в шаблон страницы предмета.
 */
class SubjectViewDTO {
	public function __construct(
		public readonly string $subject_key,
		public readonly SubjectDTO $subject_data,
		public readonly array $task_types,
		public readonly array $all_templates,
		public readonly string $tasks_url,
		public readonly string $articles_url,
		public readonly string $protected_tax,
		public readonly array $taxonomies
	) {}
}