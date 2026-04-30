<?php

namespace Inc\DTO;

/**
 * Class SubjectDTO
 *
 * Data Transfer Object для передачи данных о предмете.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Типобезопасная передача** — обеспечивает строгую типизацию данных предмета.
 * 2. **Инкапсуляция данных** — объединяет ключ и название предмета в один объект.
 *
 * ### Архитектурная роль:
 *
 * Используется для передачи данных между слоями:
 * - Из SubjectRepository в SubjectController
 * - Из SubjectController в представление (шаблон)
 */
readonly class SubjectDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param string $key  Уникальный идентификатор предмета (slug), например 'math' или 'physics'
	 * @param string $name Отображаемое название предмета, например 'Математика' или 'Физика'
	 */
	public function __construct(
		public string $key,
		public string $name
	) {
	}
}