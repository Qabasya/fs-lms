<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleCardDTO
 *
 * Карточка статьи в каталоге учебника.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleCardDTO {

	/**
	 * @param int                     $id        ID записи статьи.
	 * @param string                  $title     Заголовок.
	 * @param string                  $url       Адрес статьи.
	 * @param string                  $excerpt   Короткое описание для карточки.
	 * @param string                  $thumbnail Обложка; '' — рисуем заглушку.
	 * @param int                     $minutes   Время чтения в минутах; 0 — не показываем.
	 * @param array<string, string[]> $terms     Термины статьи: [taxonomy => term_slugs].
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $url,
		public string $excerpt = '',
		public string $thumbnail = '',
		public int $minutes = 0,
		public array $terms = array(),
	) {}

	/**
	 * Термины карточки строкой токенов `taxonomy:slug` — по ним фильтрует JS.
	 *
	 * Одна строка вместо атрибута на таксономию: фильтров у предмета сколько
	 * угодно, а разбор `data-*` с динамическими именами в JS читается хуже.
	 *
	 * @return string
	 */
	public function termTokens(): string {
		$tokens = array();

		foreach ( $this->terms as $taxonomy => $slugs ) {
			foreach ( $slugs as $slug ) {
				$tokens[] = $taxonomy . ':' . $slug;
			}
		}

		return implode( ' ', $tokens );
	}
}