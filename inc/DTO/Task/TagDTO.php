<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class TagDTO
 *
 * Чип-классификатор задания: тип задания (фиксированная таксономия
 * {subject}_task_number) либо термин обязательной пользовательской таксономии
 * предмета (год, источник/автор).
 *
 * $url ведёт не в архив термина, а на «Все задания» с предвыбранным фильтром
 * (TaskDataBuilder::filterUrl); пустая строка — ссылки нет, чип неактивен.
 *
 * @package Inc\DTO\Task
 */
readonly class TagDTO {

	public const TYPE_TASK_TYPE = 'task_type';
	public const TYPE_TAXONOMY  = 'taxonomy';

	/**
	 * @param string $type          Тип чипа: self::TYPE_TASK_TYPE|self::TYPE_TAXONOMY.
	 * @param string $label         Подпись чипа.
	 * @param string $taxonomy      Слаг таксономии.
	 * @param string $taxonomy_name Отображаемое имя таксономии.
	 * @param int    $term_id       ID термина.
	 * @param string $slug          Слаг термина.
	 * @param string $url           Ссылка на «Все задания» с этим фильтром.
	 * @param int    $color         Ступень палитры чипа (TagPaletteService); 0 — нейтральный.
	 */
	public function __construct(
		public string $type,
		public string $label,
		public string $taxonomy,
		public string $taxonomy_name,
		public int $term_id,
		public string $slug,
		public string $url,
		public int $color = 0,
	) {}
}