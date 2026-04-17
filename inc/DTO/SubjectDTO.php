<?php

namespace Inc\DTO;

/**
 * Class SubjectDTO
 *
 * Data Transfer Object для передачи данных о предмете.
 * Используется для типобезопасной передачи данных между слоями:
 * - из Репозитория в Контроллер
 * - из Контроллера в Представление (шаблон)
 *
 * @package Inc\DTO
 */
class SubjectDTO {
	/**
	 * Конструктор DTO.
	 *
	 * @param string $key  Уникальный идентификатор предмета (slug)
	 * @param string $name Отображаемое название предмета
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $name
	) {
	}
}