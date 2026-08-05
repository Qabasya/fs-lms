<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class HeadingDTO
 *
 * Подзаголовок статьи для блока «Содержание».
 *
 * Собирается пост-обработкой контента ({@see \Inc\Services\Subject\ArticleContentService}):
 * редактор WordPress `id` заголовкам не проставляет, поэтому и якорь, и пункт
 * оглавления рождаются в одном месте — иначе ссылка вела бы в никуда.
 *
 * @package Inc\DTO\Article
 */
readonly class HeadingDTO {

	/**
	 * @param string $id    Якорь заголовка (значение атрибута id).
	 * @param string $text  Текст заголовка.
	 * @param int    $level Уровень: 2 — раздел, 3 — подраздел.
	 */
	public function __construct(
		public string $id,
		public string $text,
		public int $level,
	) {}
}