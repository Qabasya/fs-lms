<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticleNavigationDTO
 *
 * Навигация страницы статьи: соседние статьи того же номера задания, ссылка на
 * учебник и позиция текущей статьи в серии.
 *
 * Серия из трёх и более статей замкнута в кольцо, как на странице задания: за
 * последней статьёй идёт первая. В серии из двух переход один — вторая сторона
 * null, и шаблон растягивает единственную карточку на всю ширину.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticleNavigationDTO {

	/** Минимальная длина серии, при которой есть что переключать. */
	public const MIN_SERIES = 2;

	/**
	 * @param AdjacentArticleDTO|null $prev         Предыдущая статья серии; null — перехода назад нет.
	 * @param AdjacentArticleDTO|null $next         Следующая статья серии; null — перехода вперёд нет.
	 * @param string                  $articles_url Учебник предмета с фильтром по номеру задания серии.
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
		return $this->total < self::MIN_SERIES || ( ! $this->prev && ! $this->next );
	}

	/**
	 * Переходы, которые есть, в порядке «назад → вперёд».
	 *
	 * @return array<string, AdjacentArticleDTO> Ключ — сторона: `prev` / `next`.
	 */
	public function sides(): array {
		return array_filter(
			array(
				'prev' => $this->prev,
				'next' => $this->next,
			)
		);
	}
}
