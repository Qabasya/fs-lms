<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

use Inc\Enums\Subject\TaskTemplate;

/**
 * Class TaskTypeDTO
 *
 * Data Transfer Object для передачи данных о типе задания (термине таксономии).
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение данных термина** — объединяет ID, слаг, таксономию, описание и шаблон.
 * 2. **Совместимость с legacy-кодом** — предоставляет строковый ID шаблона для обратной совместимости.
 *
 * ### Архитектурная роль:
 *
 * Используется для типобезопасной передачи данных о типах заданий (номерах заданий)
 * между слоями: из репозитория в контроллер и из контроллера в представление (шаблон).
 */
readonly class TaskTypeDTO {
	
	/**
	 * Отображаемое название типа задания (берётся из описания термина).
	 *
	 * @var string
	 */
	public string $name;
	
	/**
	 * Конструктор DTO.
	 *
	 * @param int          $id               ID термина таксономии
	 * @param string       $slug             Слаг термина (например, '1', '2', 'task_1')
	 * @param string       $taxonomy         Имя таксономии (например, 'math_task_number')
	 * @param string       $description      Описание термина (отображаемое название, например 'Задание №1')
	 * @param TaskTemplate $current_template Текущий шаблон для этого типа задания (enum)
	 * @param string       $raw_id           Строковый ID шаблона для обратной совместимости
	 * @param int          $post_count       Количество заданий, созданных для этого типа
	 */
	public function __construct(
		public int $id,
		public string $slug,
		public string $taxonomy,
		public string $description,
		public TaskTemplate $current_template,
		public string $raw_id,
		public int $post_count = 0,
	) {
		$this->name = $description;
	}
	
	/**
	 * Возвращает строковый ID шаблона.
	 * Полезно для обратной совместимости, если где-то на фронте
	 * или в устаревшем коде всё ещё нужна строка вместо enum.
	 *
	 * @return string Строковый идентификатор шаблона (например, 'standard_task')
	 */
	public function getTemplateId(): string {
		return $this->raw_id;
	}

	public static function fromArray( array $data ): self {
		return new self(
			id:               (int)    ( $data['id'] ?? 0 ),
			slug:             (string) ( $data['slug'] ?? '' ),
			taxonomy:         (string) ( $data['taxonomy'] ?? '' ),
			description:      (string) ( $data['description'] ?? '' ),
			current_template: TaskTemplate::fromDatabase( $data['current_template'] ?? null ),
			raw_id:           (string) ( $data['raw_id'] ?? '' ),
			post_count:       (int)    ( $data['post_count'] ?? 0 ),
		);
	}

	public function toArray(): array {
		return array(
			'id'               => $this->id,
			'slug'             => $this->slug,
			'taxonomy'         => $this->taxonomy,
			'description'      => $this->description,
			'current_template' => $this->current_template->value,
			'raw_id'           => $this->raw_id,
			'post_count'       => $this->post_count,
		);
	}
}