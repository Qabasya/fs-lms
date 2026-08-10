<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleSectionDTO
 *
 * Секция каталога учебника — статьи одного номера задания.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleSectionDTO {

	/**
	 * @param string           $label    Заголовок секции («Задание №1»).
	 * @param string           $anchor   Якорь секции (слаг термина или 'other').
	 * @param ArticleCardDTO[] $articles Карточки секции в порядке чтения.
	 */
	public function __construct(
		public string $label,
		public string $anchor,
		public array $articles = array(),
	) {}

	/**
	 * Сколько статей в секции.
	 *
	 * @return int
	 */
	public function total(): int {
		return count( $this->articles );
	}
}