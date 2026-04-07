<?php

namespace Inc\DTO;

/**
 * Class TaxonomyDataDTO
 *
 * Data Transfer Object для передачи данных о таксономии.
 * Используется для типобезопасной передачи данных между слоями:
 * - из AJAX-запроса в Репозиторий
 * - из Репозитория в Контроллер
 * - из Контроллера в Представление (шаблон)
 *
 * @package Inc\DTO
 */
class TaxonomyDataDTO
{
	/**
	 * Конструктор DTO.
	 *
	 * @param string $slug          Уникальный идентификатор таксономии (tax_slug)
	 * @param string $name          Отображаемое название таксономии
	 * @param string $subject_key   Ключ предмета, к которому привязана таксономия
	 * @param bool   $is_protected  Флаг защищённой таксономии (нельзя редактировать/удалять)
	 * @param array  $post_types    Массив типов постов, к которым привязана таксономия
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $name,
		public readonly string $subject_key,
		public readonly bool $is_protected = false,
		public readonly array $post_types = []
	) {
	}

	/**
	 * Статический фабричный метод для создания DTO из массива.
	 *
	 * Удобен для преобразования данных из базы WordPress (опции) или из $_POST.
	 * Позволяет не писать конструктор каждый раз в контроллерах и репозиториях.
	 *
	 * @param string $slug        Слаг таксономии (уникальный идентификатор)
	 * @param array  $data        Массив с данными таксономии
	 * @param string $subject_key Ключ предмета (опционально, может быть в $data)
	 *
	 * @return self Созданный DTO-объект
	 */
	public static function fromArray(string $slug, array $data, string $subject_key = ''): self
	{
		return new self(
			slug: $slug,
			name: $data['name'] ?? '',
			subject_key: $subject_key ?: ($data['subject_key'] ?? ''),
			is_protected: $data['is_protected'] ?? false,
			post_types: $data['post_types'] ?? []
		);
	}
}