<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class PostViewDTO
 *
 * DTO для представления данных записи WordPress на frontend.
 *
 * Хранит предвычисленные данные записи (заголовок, PageRoutes и т.д.),
 * чтобы не обращаться к WP API повторно в шаблонах.
 * Создаётся через фабричный метод fromPost().
 *
 * @package Inc\DTO
 */
readonly class PostViewDTO {

	/**
	 * @param int    $id        ID записи.
	 * @param string $title     Заголовок записи.
	 * @param string $slug      Slug записи (post_name).
	 * @param string $post_type Тип записи WordPress.
	 * @param string $url       Постоянная ссылка на запись.
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $slug,
		public string $post_type,
		public string $url,
	) {}

	/**
	 * Создаёт DTO из объекта WP_Post.
	 *
	 * Вычисляет заголовок и PageRoutes через WP API и сохраняет в объекте.
	 * Возвращает null, если запись не передана.
	 *
	 * @param \WP_Post|null $post Объект записи WordPress.
	 *
	 * @return self|null
	 */
	public function toArray(): array {
		return array(
			'id'        => $this->id,
			'title'     => $this->title,
			'slug'      => $this->slug,
			'post_type' => $this->post_type,
			'url'       => $this->url,
		);
	}

	public static function normalizePost( ?\WP_Post $post ): ?self {
		if ( ! $post ) {
			return null;
		}

		return new self(
			id:        (int) $post->ID,
			title:     (string) get_the_title( $post->ID ),
			slug:      $post->post_name,
			post_type: $post->post_type,
			url:       (string) get_permalink( $post->ID ),
		);
	}
}