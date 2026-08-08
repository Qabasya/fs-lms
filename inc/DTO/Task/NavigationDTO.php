<?php

declare( strict_types=1 );

namespace Inc\DTO\Task;

/**
 * Class NavigationDTO
 *
 * Навигация страницы задания: крошки, ссылка на архив и соседние задания.
 *
 * @package Inc\DTO\Task
 */
readonly class NavigationDTO {

	/**
	 * @param array                $breadcrumbs Плоский список крошек от BreadcrumbsBuilder
	 *                                          для общего партиала partials/breadcrumbs.php.
	 * @param string               $archive_url Ссылка на тренажёр предмета («Все задания»).
	 * @param AdjacentTaskDTO|null $prev        Предыдущее задание.
	 * @param AdjacentTaskDTO|null $next        Следующее задание.
	 */
	public function __construct(
		public array $breadcrumbs = array(),
		public string $archive_url = '',
		public ?AdjacentTaskDTO $prev = null,
		public ?AdjacentTaskDTO $next = null,
	) {}
}