<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class AdjacentArticleDTO
 *
 * Соседняя статья серии для блока навигации страницы статьи.
 *
 * @package Inc\DTO\Article
 */
readonly class AdjacentArticleDTO {

	/**
	 * @param string $title       Заголовок статьи.
	 * @param string $url         Пермалинк статьи.
	 * @param string $description Краткое описание для карточки перехода.
	 * @param string $thumbnail   URL обложки; '' — вместо неё заглушка.
	 * @param bool   $wrapped     Переход через край серии: сторона ведёт не к
	 *                            соседу, а на другой конец кольца. Подпись по
	 *                            этому флагу выбирает шаблон.
	 */
	public function __construct(
		public string $title,
		public string $url,
		public string $description = '',
		public string $thumbnail = '',
		public bool $wrapped = false,
	) {}
}
