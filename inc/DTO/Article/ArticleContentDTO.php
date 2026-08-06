<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleContentDTO
 *
 * Готовый к выводу контент статьи и его оглавление.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleContentDTO {

	/**
	 * @param string       $html     HTML статьи после `the_content` и пост-обработки.
	 * @param HeadingDTO[] $headings Подзаголовки h2/h3 в порядке следования.
	 */
	public function __construct(
		public string $html = '',
		public array $headings = array(),
	) {}
}