<?php

declare( strict_types=1 );

namespace Inc\DTO;

/**
 * Class AllTasksPageDTO
 *
 * Данные первичного рендера (SSR) страницы «Все задания».
 *
 * $filters — группы фильтров сайдбара, каждая соответствует таксономии предмета
 * (тип задания + пользовательские: год, источник/автор и др.):
 *   [{ taxonomy, name, terms: [{ slug, name, count, url }] }]
 *
 * @package Inc\DTO
 */
readonly class AllTasksPageDTO {

	/**
	 * @param string $subject_key  Ключ предмета.
	 * @param string $subject_name Отображаемое имя предмета.
	 * @param array  $filters      Группы фильтров-таксономий (см. описание класса).
	 * @param array  $tasks        Первая страница: TaskListItemDTO[].
	 * @param int    $total        Полное число заданий с учётом фильтров.
	 * @param int    $per_page     Размер страницы.
	 * @param bool   $has_more     Есть ли ещё страницы (для infinite scroll).
	 * @param string $nonce        Nonce для AJAX-подгрузки.
	 */
	public function __construct(
		public string $subject_key,
		public string $subject_name,
		public array  $filters,
		public array  $tasks,
		public int    $total,
		public int    $per_page,
		public bool   $has_more,
		public string $nonce,
	) {}
}