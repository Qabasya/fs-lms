<?php

namespace Inc\DTO;

use Inc\Enums\TaskTemplate;

/**
 * Class TaskTypeDTO
 *
 * Data Transfer Object для передачи данных о типе задания (термине таксономии).
 * Используется для типобезопасной передачи данных между слоями:
 * - из Репозитория в Контроллер
 * - из Контроллера в Представление (шаблон)
 *
 * @package Inc\DTO
 */
class TaskTypeDTO {
	/**
	 * Отображаемое название типа задания (берётся из описания термина).
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * Конструктор DTO.
	 *
	 * @param int $id ID термина таксономии
	 * @param string $slug Слаг термина (например, "1", "2")
	 * @param string $taxonomy Имя таксономии (например, "math_task_number")
	 * @param string $description Описание термина (отображаемое название)
	 * @param TaskTemplate $current_template Текущий шаблон для этого типа задания (enum)
	 * @param string $raw_id Строковый ID шаблона для обратной совместимости
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $taxonomy,
		public readonly string $description,
		public readonly TaskTemplate $current_template,
		public readonly string $raw_id,
		public readonly int $post_count = 0,
	) {
		$this->name = $description;
	}

	/**
	 * Возвращает строковый ID шаблона.
	 *
	 * Полезный метод для обратной совместимости, если где-то на фронте
	 * или в устаревшем коде всё ещё нужна строка вместо enum.
	 *
	 * @return string Строковый идентификатор шаблона (например, "standard_task")
	 */
	public function getTemplateId(): string {
		return $this->raw_id;
	}
}