<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

use Inc\DTO\Task\PostViewDTO;

/**
 * Class ArticlePageDTO
 *
 * Данные frontend-страницы статьи (templates/frontend/single-article.php).
 *
 * @package Inc\DTO\Article
 */
readonly class ArticlePageDTO {

	/**
	 * @param PostViewDTO|null                     $post        Запись статьи; null — статьи нет.
	 * @param string                               $subject_key Ключ предмета.
	 * @param ArticleContentDTO                    $content     Контент и оглавление.
	 * @param array<int, array<string, mixed>>     $breadcrumbs Крошки для общего партиала.
	 * @param \Inc\DTO\Course\CourseCardDTO[]      $courses     Курсы предмета для сайдбара.
	 * @param array<int, array<string, mixed>>     $recommended Статьи блока «Читать далее».
	 */
	public function __construct(
		public ?PostViewDTO $post,
		public string $subject_key,
		public ArticleContentDTO $content,
		public array $breadcrumbs,
		public array $courses,
		public array $recommended,
	) {}

	/**
	 * Пустой DTO: запись не найдена или не является статьёй.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self(
			post:        null,
			subject_key: '',
			content:     new ArticleContentDTO(),
			breadcrumbs: array(),
			courses:     array(),
			recommended: array(),
		);
	}
}