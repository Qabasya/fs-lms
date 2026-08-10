<?php

declare( strict_types=1 );

namespace Inc\DTO\Subject;

/**
 * Class SubjectLinksDTO
 *
 * Ссылки на разделы предмета для публичных страниц: крошки, сайдбар,
 * навигация, чипы-фильтры.
 *
 * Значения уже с фолбэками (см. SubjectPagesService::links()): страницы
 * лендинга, а где их нет — архивы CPT. Потребителю остаётся только вывести
 * ссылку либо, если она пуста, скрыть элемент.
 *
 * @package Inc\DTO\Subject
 */
readonly class SubjectLinksDTO {

	/**
	 * @param string $subject  Корневая страница предмета (фолбэк — тренажёр).
	 * @param string $trainer  Тренажёр — задания предмета (фолбэк — архив заданий).
	 * @param string $articles Учебник — статьи предмета (фолбэк — архив статей).
	 * @param string $courses  Витрина курсов; '' — раздела нет, ссылку не выводим.
	 */
	public function __construct(
		public string $subject = '',
		public string $trainer = '',
		public string $articles = '',
		public string $courses = '',
	) {}
}