<?php

namespace Inc\DTO;

/**
 * Class TaskMetaDTO
 *
 * Data Transfer Object для конфигурации метабоксов конкретного типа задания.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Конфигурация метабокса** — передача информации о структуре метабокса в контроллер.
 * 2. **Фабричный метод** — создание DTO из массива конфигурации.
 *
 * ### Архитектурная роль:
 *
 * Используется в MetaBoxController для передачи данных о шаблонах
 * в фильтр 'fs_lms_get_templates'. Служит унифицированным форматом
 * для регистрации шаблонов метабоксов.
 */
readonly class TaskMetaDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param string $id          Уникальный идентификатор шаблона (например, 'standard_task')
	 * @param string $title       Отображаемое название шаблона (например, 'Стандартное задание')
	 * @param array  $fields      Массив конфигураций полей для метабокса (id => конфиг)
	 * @param string $description Описание шаблона (опционально)
	 */
	public function __construct(
		public string $id,
		public string $title,
		public array $fields,
		public string $description = ''
	) {
	}
	
	/**
	 * Статический фабричный метод для создания DTO из массива конфигурации.
	 * Удобен для преобразования данных из конфигурационных файлов.
	 *
	 * @param string $id     Уникальный идентификатор шаблона
	 * @param array  $config Массив конфигурации с полями:
	 *                       - title: отображаемое название
	 *                       - fields: список полей (массив)
	 *                       - description: описание (опционально)
	 *
	 * @return self
	 */
	public static function fromArray( string $id, array $config ): self
	{
		return new self(
			id         : $id,
			title      : $config['title'] ?? $id,
			fields     : $config['fields'] ?? [],
			description: $config['description'] ?? ''
		);
	}
}