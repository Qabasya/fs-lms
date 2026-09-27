<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\CourseCardDTO;
use Inc\DTO\Course\CourseDTO;
use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Course\CourseManager;

/**
 * Class PublicCourseService
 *
 * Курсы предмета для публичных страниц (сайдбар «Все задания» и страницы задания).
 *
 * Показываются только опубликованные курсы: черновики и архив — внутренние
 * состояния конструктора. Приватные курсы (тумблер «Публичный» в конструкторе,
 * CourseManager::isPublic()) тоже не выводятся. Если подходящих нет, метод
 * возвращает пустой список, и блок сайдбара не выводится вовсе.
 *
 * @package Inc\Services\Course
 */
class PublicCourseService {

	/** Сколько курсов показывает сайдбар. */
	private const LIMIT = 2;

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
				'status'     => 'publish',
				'limit'      => $limit,
				'orderby'    => 'title',
				'order'      => 'ASC',
				'meta_query' => $this->course_manager->publicMetaQuery(),
			)
		);

		return array_map( fn( CourseDTO $course ) => $this->toCard( $course ), $courses );
	}

	/**
	 * Предмет опубликованного курса — направление, которое форма заявки
	 * выбирает сама, когда посетитель пришёл по «Записаться» (`?course=ID`).
	 *
	 * @param int $course_id ID курса.
	 *
	 * @return string Ключ предмета; пустая строка — курса нет или он не опубликован.
	 */
	public function subjectOf( int $course_id ): string {
		if ( $course_id < 1 ) {
			return '';
		}

		$course = $this->course_manager->get( $course_id );

		return null !== $course && 'publish' === $course->status ? $course->subjectKey : '';
	}

	/**
	 * Опубликованные курсы предмета для сайдбара.
	 *
	 * @param string $subject_key Ключ предмета.
	 * @param int    $limit       Сколько курсов вернуть.
	 *
	 * @return CourseCardDTO[]
	 */
	public function getSidebarCourses( string $subject_key, int $limit = self::LIMIT ): array {
		return $this->getCourses( $subject_key, $limit );
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