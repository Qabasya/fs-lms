<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class TermViewDTO
 *
 * DTO для представления термина таксономии WordPress на frontend.
 *
 * Хранит нормализованные данные термина: id вместо term_id, чтобы
 * не зависеть от структуры WP_Term в шаблонах и сервисах.
 * Создаётся через фабричный метод fromTerm().
 *
 * @package Inc\DTO
 */
readonly class TermViewDTO {

	/**
	 * @param int    $id       ID термина (из WP_Term::term_id).
	 * @param string $name     Название термина.
	 * @param string $slug     Slug термина.
	 * @param string $taxonomy Слаг таксономии, которой принадлежит термин.
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $slug,
		public string $taxonomy,
	) {}

	/**
	 * Создаёт DTO из объекта WP_Term.
	 *
	 * Возвращает null, если термин не передан.
	 *
	 * @param \WP_Term|null $term Объект термина WordPress.
	 *
	 * @return self|null
	 */
	public function toArray(): array {
		return array(
			'id'       => $this->id,
			'name'     => $this->name,
			'slug'     => $this->slug,
			'taxonomy' => $this->taxonomy,
		);
	}

	public static function normalizeTerm( ?\WP_Term $term ): ?self {
		if ( ! $term ) {
			return null;
		}

		return new self(
			id:       $term->term_id,
			name:     $term->name,
			slug:     $term->slug,
			taxonomy: $term->taxonomy,
		);
	}
}