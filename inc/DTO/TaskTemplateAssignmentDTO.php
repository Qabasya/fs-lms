<?php

namespace Inc\DTO;

/**
 * Class TaskTemplateAssignmentDTO
 *
 * Data Transfer Object для хранения информации о назначении шаблона
 * конкретному номеру задания в определённом предмете.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение привязки** — объединяет ключ предмета, номер задания и ID шаблона.
 * 2. **Типобезопасная передача** — обеспечивает строгую типизацию данных о привязке.
 *
 * ### Архитектурная роль:
 *
 * Используется в MetaBoxRepository для передачи данных о том,
 * какой шаблон метабокса привязан к конкретному номеру задания в предмете.
 */
readonly class TaskTemplateAssignmentDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param string $subject_key Ключ предмета (slug), например 'math' или 'physics'
	 * @param string $task_number Номер задания (например, '1', '2' или 'task_1')
	 * @param string $template_id Идентификатор шаблона метабокса (например, 'standard_task')
	 */
	public function __construct(
		public string $subject_key,
		public string $task_number,
		public string $template_id
	) {
	}
}