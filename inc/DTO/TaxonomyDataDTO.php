<?php

namespace Inc\DTO;

/**
 * Class TaxonomyDataDTO
 *
 * Data Transfer Object для передачи данных о таксономии.
 *
 * @package Inc\DTO
 *
 * ### Основные обязанности:
 *
 * 1. **Хранение данных таксономии** — объединяет слаг, название, тип отображения и флаги.
 * 2. **Фабричный метод** — создание DTO из массива данных (удобно для БД и $_POST).
 *
 * ### Архитектурная роль:
 *
 * Используется для типобезопасной передачи данных о таксономиях между слоями:
 * - из AJAX-запроса в репозиторий
 * - из репозитория в контроллер
 * - из контроллера в представление (шаблон)
 */
readonly class TaxonomyDataDTO {
	
	/**
	 * Конструктор DTO.
	 *
	 * @param string $slug         Уникальный идентификатор таксономии (tax_slug), например 'math_author'
	 * @param string $name         Отображаемое название таксономии, например 'Автор'
	 * @param string $subject_key  Ключ предмета, к которому привязана таксономия, например 'math'
	 * @param string $display_type Тип отображения метабокса: 'select', 'radio', 'checkbox'
	 * @param bool   $is_protected Флаг защищённой таксономии (нельзя редактировать/удалять)
	 * @param bool   $is_required  Флаг обязательной таксономии (должна быть выбрана при публикации)
	 * @param array  $post_types   Массив типов постов, к которым привязана таксономия
	 */
	public function __construct(
		public string $slug,
		public string $name,
		public string $subject_key,
		public string $display_type = 'select',
		public bool $is_protected = false,
		public bool $is_required = false,
		public array $post_types = array()
	) {
	}
	
	/**
	 * Статический фабричный метод для создания DTO из массива.
	 *
	 * @param string $slug        Слаг таксономии (уникальный идентификатор)
	 * @param array  $data        Массив с данными таксономии
	 * @param string $subject_key Ключ предмета (опционально, может быть в $data)
	 *
	 * @return self
	 */
	public static function fromArray( string $slug, array $data, string $subject_key = '' ): self {
		return new self(
			slug        : $slug,
			name        : $data['name'] ?? '',
			subject_key : $subject_key ?: ( $data['subject_key'] ?? '' ),
			display_type: $data['display_type'] ?? 'select',
			is_protected: $data['is_protected'] ?? false,
			is_required : (bool) ( $data['is_required'] ?? false ),
			post_types  : $data['post_types'] ?? array()
		);
	}
}