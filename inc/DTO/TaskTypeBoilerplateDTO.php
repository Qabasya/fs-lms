<?php

namespace Inc\DTO;

/**
 * Class TaskTypeBoilerplateDTO
 * * Объект для передачи данных о типовом условии задания.
 * Представляет один конкретный вариант типового условия (boilerplate).
 */
class TaskTypeBoilerplateDTO {
	public function __construct(
		public readonly string $uid,          // Уникальный ID условия (например, uniqid())
		public readonly string $subject_key,  // Предмет (math)
		public readonly string $term_slug,    // Номер задания (math_1)
		public readonly string $title,        // Комментарий/Название (например: "Сложные графы")
		public readonly string $content,      // JSON с текстами полей
		public readonly bool $is_default = false
	) {
	}
	
	/**
	 * Преобразовать DTO в массив для сохранения в БД
	 */
	public function toArray(): array {
		return [
			'uid'        => $this->uid,
			'title'      => $this->title,
			'content'    => $this->content,
			'is_default' => $this->is_default
		];
	}
}