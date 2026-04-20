<?php

namespace Inc\DTO;

/**
 * Class TaskTemplateAssignmentDTO
 *
 * Data Transfer Object для хранения информации о назначении шаблона
 * конкретному номеру задания в определённом предмете.
 *
 * Используется для типобезопасной передачи данных о привязке
 * заданий к шаблонам метабоксов между слоями приложения.
 *
 * @package Inc\DTO
 */
class TaskTemplateAssignmentDTO
{
    /**
     * Конструктор DTO.
     *
     * @param string $subject_key Ключ предмета (slug), к которому относится задание
     * @param string $task_number Номер задания (например, "1" или "task_1")
     * @param string $template_id Идентификатор шаблона метабокса (например, "standard_task")
     */
    public function __construct(
        public readonly string $subject_key,
        public readonly string $task_number,
        public readonly string $template_id
    ) {
    }
}
