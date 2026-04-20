<?php

namespace Inc\DTO;

/**
 * Class TaskMetaDTO
 *
 * Data Transfer Object для конфигурации метабоксов конкретного типа задания.
 *
 * Используется для передачи информации о структуре метабокса:
 * - Идентификатор шаблона
 * - Отображаемое название
 * - Список полей с их конфигурацией
 * - Описание (опционально)
 *
 * @package Inc\DTO
 */
class TaskMetaDTO
{
    /**
     * Конструктор DTO.
     *
     * @param string $id          Уникальный идентификатор шаблона (например, 'standard_task')
     * @param string $title       Отображаемое название шаблона (например, 'Стандартное задание')
     * @param array  $fields      Массив конфигураций полей для метабокса
     * @param string $description Описание шаблона (опционально)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $fields,
        public readonly string $description = ''
    ) {
    }
    
    /**
     * Статический фабричный метод для создания DTO из массива конфигурации.
     *
     * Удобен для преобразования данных из конфигурационных файлов
     * или при динамическом создании шаблонов.
     *
     * @param string $id     Уникальный идентификатор шаблона
     * @param array  $config Массив конфигурации с полями:
     *                       - title: отображаемое название
     *                       - fields: список полей
     *                       - description: описание (опционально)
     *
     * @return self Созданный DTO-объект
     */
    public static function fromArray(string $id, array $config): self
    {
        return new self(
            id         : $id,
            title      : $config['title'] ?? $id,
            fields     : $config['fields'] ?? [],
            description: $config['description'] ?? ''
        );
    }
}
