<?php

declare( strict_types=1 );

namespace Inc\DTO\Application;

/**
 * Class ApplicationDecryptedDTO
 *
 * Расшифрованная версия заявки для отображения.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Объединение данных** — связывает информацию о заявке с расшифрованными данными студента и родителя.
 *
 * ### Архитектурная роль:
 *
 * Создаётся сервисами, не репозиториями. Используется для передачи данных в представление
 * (шаблоны, API) после расшифровки персональных данных.
 *
 * ### Примечания:
 *
 * - parentData = null до момента заполнения формы родителем.
 * - Все поля уже расшифрованы и безопасны для отображения.
 */
readonly class ApplicationDecryptedDTO {

	/**
	 * Конструктор DTO.
	 *
	 * @param int             $applicationId ID заявки
	 * @param StudentDataDTO  $studentData   Данные студента (расшифрованные)
	 * @param ParentDataDTO|null $parentData Данные родителя (расшифрованные), null если не заполнены
	 */
	public function __construct(
		public int             $applicationId,
		public StudentDataDTO  $studentData,
		public ?ParentDataDTO  $parentData,
	) {}
}