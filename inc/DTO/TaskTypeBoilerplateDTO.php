<?php

namespace Inc\DTO;

/**
 * Class TaskTypeBoilerplateDTO
 * * Объект для передачи данных о типовом условии задания.
 */
class TaskTypeBoilerplateDTO {
	public function __construct(
		public string $subject_key,
		public string $term_slug,
		public string $text
	) {}
}