<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleNavigationDTO
 *
 * Навигация страницы статьи: соседние статьи того же номера задания, ссылка на
 * учебник и позиция текущей статьи в серии.
 *
 * Кольца, как на странице задания, здесь нет: на краях серии сторона остаётся
 * неактивной.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleNavigationDTO {

	/**
	 * @param AdjacentArticleDTO|null $prev         Предыдущая статья серии; null — текущая первая.
	 * @param AdjacentArticleDTO|null $next         Следующая статья серии; null — текущая последняя.
	 * @param string                  $textbook_url Ссылка на учебник (архив статей предмета).
	 * @param int                     $position     Номер текущей статьи в серии, с единицы.
	 * @param int                     $total        Сколько всего статей в серии.
	 */
	public function __construct(
		public ?AdjacentArticleDTO $prev = null,
		public ?AdjacentArticleDTO $next = null,
		public string $textbook_url = '',
		public int $position = 0,
		public int $total = 0,
	) {}

	/**
	 * Серии нет — блок навигации не рендерим.
	 *
	 * Так бывает, когда у статьи не проставлен номер задания: множество «статей
	 * того же типа» не определено, считать и переключать нечего.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return $this->total < 1;
	}
}