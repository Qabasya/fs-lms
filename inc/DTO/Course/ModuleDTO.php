<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class ModuleDTO
 *
 * Раздел курса (Courses.md → ★): course-local, НЕ отдельный CPT. Хранится в meta
 * курса в массиве `modules[]`. Группирует упорядоченные ссылки на уроки; при назначении
 * группе `id`/`title` снапшотятся в `group_lessons` (§7 №23). `id` стабилен и генерируется
 * в сервисе при добавлении модуля.
 *
 * @package Inc\DTO\Course
 */
readonly class ModuleDTO {

	/**
	 * @param string $id        Стабильный course-local идентификатор модуля.
	 * @param string $title     Заголовок модуля.
	 * @param int[]  $lessonIds Упорядоченные ссылки на {key}_lessons.
	 */
	public function __construct(
		public string $id,
		public string $title,
		public array  $lessonIds,
	) {}

	public static function fromArray( array $data ): self {
		return new self(
			id       : (string) ( $data['id'] ?? '' ),
			title    : (string) ( $data['title'] ?? '' ),
			lessonIds: self::intIds( $data['lesson_ids'] ?? array() ),
		);
	}

	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'title'      => $this->title,
			'lesson_ids' => $this->lessonIds,
		);
	}

	public function isEmpty(): bool {
		return empty( $this->lessonIds );
	}

	/**
	 * Десериализует meta-массив `modules[]` в список DTO (нечитаемые элементы отбрасываются).
	 *
	 * @param array<int, mixed> $rows
	 *
	 * @return self[]
	 */
	public static function fromList( array $rows ): array {
		$modules = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$modules[] = self::fromArray( $row );
			}
		}

		return $modules;
	}

	/**
	 * Сериализует список DTO в meta-массив `modules[]`.
	 *
	 * @param self[] $modules
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function toList( array $modules ): array {
		return array_map( static fn( self $module ): array => $module->toArray(), $modules );
	}

	/**
	 * @param mixed $raw
	 *
	 * @return int[]
	 */
	private static function intIds( mixed $raw ): array {
		return is_array( $raw )
			? array_values( array_filter( array_map( 'intval', $raw ) ) )
			: array();
	}
}
