<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleNavigationDTO
 *
 * Навигация страницы статьи: соседние статьи того же номера задания, ссылка на
 * учебник и позиция текущей статьи в серии.
 *
 * Серия замкнута в кольцо, как на странице задания: за последней статьёй идёт
 * первая. В серии из двух статей обе стороны ведут в одного и того же соседа —
 * зато пустой половины в блоке не бывает.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleNavigationDTO {

	/** Минимальная длина серии, при которой есть что переключать. */
	public const MIN_SERIES = 2;

	/**
	 * @param AdjacentArticleDTO|null $prev         Предыдущая статья серии; null — серии нет.
	 * @param AdjacentArticleDTO|null $next         Следующая статья серии; null — серии нет.
	 * @param string                  $articles_url Ссылка на учебник предмета.
	 * @param int                     $position     Номер текущей статьи в серии, с единицы.
	 * @param int                     $total        Сколько всего статей в серии.
	 */
	public function __construct(
		public ?AdjacentArticleDTO $prev = null,
		public ?AdjacentArticleDTO $next = null,
		public string $articles_url = '',
		public int $position = 0,
		public int $total = 0,
	) {}

	/**
	 * Переключать нечего — блок навигации не рендерим.
	 *
	 * Так бывает, когда у статьи не проставлен номер задания либо она в серии
	 * одна: кольцо из одной статьи вело бы саму на себя.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->total < self::MIN_SERIES || ! $this->prev || ! $this->next;
	}
}
