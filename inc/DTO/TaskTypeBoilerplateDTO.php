<?php

namespace Inc\DTO;

/**
 * Class TaskTypeBoilerplateDTO
 *
 * Data Transfer Object для передачи данных о типовом условии задания (boilerplate).
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение данные boilerplate** — объединяет UID, ключ предмета, слаг термина, название и контент.
 * 2. **Преобразование в массив** — метод toArray() для сохранения в базу данных.
 *
 * ### Архитектурная роль:
 *
 * Используется в BoilerplateRepository и BoilerplateCallbacks для передачи
 * данных о типовых условиях (шаблонах ответов) между слоями приложения.
 */
readonly class TaskTypeBoilerplateDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param string $uid          Уникальный ID условия (например, uniqid('bp_'))
	 * @param string $subject_key  Ключ предмета (например, 'math')
	 * @param string $term_slug    Слаг термина (номер задания, например 'math_1')
	 * @param string $title        Комментарий/название (например, 'Сложные графы')
	 * @param string $content      JSON-строка с текстами полей (ConditionField)
	 * @param bool   $is_default   Флаг, является ли этот шаблон значением по умолчанию
	 */
	public function __construct(
		public string $uid,
		public string $subject_key,
		public string $term_slug,
		public string $title,
		public string $content,
		public bool $is_default = false
	) {
	}
	
	/**
	 * Преобразует DTO в массив для сохранения в БД.
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'uid'        => $this->uid,
			'title'      => $this->title,
			'content'    => $this->content,
			'is_default' => $this->is_default
		];
	}
}