<?php

declare( strict_types=1 );

namespace Inc\DTO\Course;

/**
 * Class CourseCardDTO
 *
 * Курс в публичном сайдбаре страниц предмета (партиал partials/sidebar-courses.php).
 *
 * У CPT курса нет публичного пермалинка (`public = false`), поэтому $url ведёт
 * на страницу заявки с меткой курса — см. PublicCourseService.
 *
 * @package Inc\DTO\Course
 */
readonly class CourseCardDTO {

	/**
	 * @param int    $id      ID курса.
	 * @param string $title   Название курса.
	 * @param string $url     Ссылка «записаться» (страница заявки с ?course=ID).
	 * @param int    $lessons Число уроков во всех модулях курса.
	 */
	public function __construct(
		public int $id,
		public string $title,
		public string $url,
		public int $lessons,
	) {}
}