<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseCardDTO;
use Inc\DTO\Course\CourseDTO;
use Inc\Enums\Course\SidebarCoursePromo;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;

/**
 * Class PublicCourseService
 *
 * Курсы предмета для публичных страниц: витрина раздела «Курсы» лендинга и
 * блок «Курсы» сайдбара тренажёра, задания и статьи (там — заглушка программы).
 *
 * Показываются только опубликованные курсы: черновики и архив — внутренние
 * состояния конструктора. Если опубликованных нет, метод возвращает пустой
 * список, и блок сайдбара не выводится вовсе.
 *
 * @package Inc\Services\Course
 */
class PublicCourseService {

	/**
	 * @param CourseManager $course_manager Менеджер курсов (банк предмета).
	 */
	public function __construct(
		private readonly CourseManager $course_manager,
	) {}

	/**
	 * Опубликованные курсы предмета: карточки в порядке названия.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param int    $limit       Сколько курсов вернуть.
	 *
	 * @return CourseCardDTO[]
	 */
	public function getCourses( string $subject_key, int $limit ): array {
		if ( '' === $subject_key || $limit < 1 ) {
			return array();
		}

		$courses = $this->course_manager->getBankBySubject(
			$subject_key,
			array(
				'status'  => 'publish',
				'limit'   => $limit,
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);

		return array_map( fn( CourseDTO $course ) => $this->toCard( $course ), $courses );
	}

	/**
	 * Блок «Курсы» сайдбара: не реальные курсы, а заглушка программы подготовки
	 * ({@see SidebarCoursePromo}) со ссылкой на страницу предмета. Нет заглушки
	 * для предмета или нет страницы — пусто, блок не выводится.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param string $subject_url Страница предмета — ссылка «Подробнее».
	 *
	 * @return CourseCardDTO[]
	 */
	public function getSidebarCourses( string $subject_key, string $subject_url ): array {
		$promo = SidebarCoursePromo::tryFrom( $subject_key );
		if ( null === $promo || '' === $subject_url ) {
			return array();
		}

		return array(
			new CourseCardDTO(
				id:      0,
				title:   $promo->title(),
				url:     $subject_url,
				lessons: SidebarCoursePromo::LESSONS,
			),
		);
	}

	/**
	 * Приводит курс к карточке сайдбара.
	 *
	 * @param CourseDTO $course DTO курса.
	 *
	 * @return CourseCardDTO
	 */
	private function toCard( CourseDTO $course ): CourseCardDTO {
		return new CourseCardDTO(
			id:      $course->id,
			title:   $course->title,
			url:     $this->enrollUrl( $course->id ),
			lessons: count( $course->lessonIds() ),
		);
	}

	/**
	 * Ссылка «записаться на курс».
	 *
	 * Пермалинка у курса нет (CPT непубличный), поэтому ведём на страницу заявки
	 * и помечаем, с какого курса пришёл посетитель.
	 *
	 * @param int $course_id ID курса.
	 *
	 * @return string
	 */
	private function enrollUrl( int $course_id ): string {
		return (string) add_query_arg( 'course', $course_id, PageRoutes::Apply->url() );
	}
}