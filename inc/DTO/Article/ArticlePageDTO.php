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
	 * @param string                               $courses_url Витрина курсов предмета; '' — ссылки нет.
	 * @param array<int, array<string, mixed>>     $recommended Статьи блока «Читать далее».
	 * @param string                               $thumbnail   URL обложки статьи; '' — обложки нет.
	 * @param ArticleNavigationDTO                 $navigation  Соседние статьи серии и счётчик.
	 * @param string                               $trainer_url Тренажёр предмета; '' — раздела нет.
	 * @param int                                  $tasks_total Сколько заданий опубликовано в банке предмета.
	 */
	public function __construct(
		public ?PostViewDTO $post,
		public string $subject_key,
		public ArticleContentDTO $content,
		public array $breadcrumbs,
		public array $courses,
		public string $courses_url,
		public array $recommended,
		public string $thumbnail = '',
		public ArticleNavigationDTO $navigation = new ArticleNavigationDTO(),
		public string $trainer_url = '',
		public int $tasks_total = 0,
	) {}

	/**
	 * Есть ли что показывать в блоке тренажёра: и раздел, и непустой банк.
	 *
	 * @return bool
	 */
	public function hasTrainer(): bool {
		return '' !== $this->trainer_url && $this->tasks_total > 0;
	}

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
			courses_url: '',
			recommended: array(),
		);
	}
}
