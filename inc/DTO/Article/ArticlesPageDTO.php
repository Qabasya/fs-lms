<?php

declare( strict_types=1 );

namespace Inc\DTO\Article;

/**
 * Class ArticlesPageDTO
 *
 * Данные раздела «Учебник» лендинга предмета: витрина статей, сгруппированная
 * по номерам заданий, и фильтры сайдбара.
 *
 * @package Inc\DTO\Article
 */
readonly class ArticlesPageDTO {

	/**
	 * @param array<int, array<string, mixed>>  $breadcrumbs   Крошки: предмет / Учебник.
	 * @param array<int, array<string, mixed>>  $filters       Группы фильтров сайдбара.
	 * @param ArticleSectionDTO[]               $sections      Секции каталога.
	 * @param int                               $total         Сколько статей в каталоге всего.
	 * @param string                            $trainer_url   Тренажёр предмета (блок-призыв сайдбара).
	 * @param int                               $tasks_total   Сколько заданий в банке предмета.
	 */
	public function __construct(
		public array $breadcrumbs = array(),
		public array $filters = array(),
		public array $sections = array(),
		public int $total = 0,
		public string $trainer_url = '',
		public int $tasks_total = 0,
	) {}
}