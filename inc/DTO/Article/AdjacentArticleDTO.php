<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class AdjacentArticleDTO
 *
 * Соседняя статья в блоке навигации «Предыдущая / Следующая».
 *
 * @package Inc\DTO\Article
 */
readonly class AdjacentArticleDTO {

	/**
	 * @param string $title Заголовок статьи.
	 * @param string $url   Пермалинк статьи.
	 */
	public function __construct(
		public string $title,
		public string $url,
	) {}
}