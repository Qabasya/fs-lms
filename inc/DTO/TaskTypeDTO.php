<?php

namespace Inc\DTO;

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
class TaskTypeDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param int    $id          ID термина таксономии
	 * @param string $name        Название типа задания (например, "Задание 1")
	 * @param string $slug        Слаг термина (например, "1")
	 * @param string $description Описание типа задания (например, "Графы")
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $slug,
		public readonly string $description = ''
	) {
	}
}