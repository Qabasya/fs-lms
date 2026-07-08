<?php

declare( strict_types=1 );

namespace Inc\Enums\Course;

use Inc\Enums\Wp\Menu;
use Inc\Services\Subject\PostTypeResolver;

/**
 * Тип банка контента предмета (courses|lessons|works|tasks|articles).
 *
 * Заменяет шесть параллельных match/array в LearningMenuController:
 * bankMenuMap, resolveCpt, bankTitle, bankDescription,
 * subjectKeyFromPostType, bankTypeForPostType.
 */
enum BankType: string {
	case Courses     = 'courses';
	case Lessons     = 'lessons';
	case Works       = 'works';
	case Assessments = 'assessments';
	case Tasks       = 'tasks';
	case Articles    = 'articles';

	/** Пункт меню «Обучения» для этого банка. */
	public function menu(): Menu {
		return match ( $this ) {
			self::Courses     => Menu::LearningCourses,
			self::Lessons     => Menu::LearningLessons,
			self::Works       => Menu::LearningWorks,
			self::Assessments => Menu::LearningAssessments,
			self::Tasks       => Menu::LearningTasks,
			self::Articles    => Menu::LearningArticles,
		};
	}

	/** CPT-slug банка для указанного предмета. */
	public function cpt( string $key ): string {
		return match ( $this ) {
			self::Courses     => PostTypeResolver::courses( $key ),
			self::Lessons     => PostTypeResolver::lessons( $key ),
			self::Works       => PostTypeResolver::works( $key ),
			self::Assessments => PostTypeResolver::assessments( $key ),
			self::Tasks       => PostTypeResolver::tasks( $key ),
			self::Articles    => PostTypeResolver::articles( $key ),
		};
	}

	/** Заголовок страницы банка. */
	public function title(): string {
		return $this->menu()->page_title();
	}

	/** Описание-абзац над таблицей банка. */
	public function description(): string {
		return match ( $this ) {
			self::Courses     => __( 'Банк курсов предмета. Курс состоит из уроков и назначается учебным группам.', 'fs-lms' ),
			self::Lessons     => __( 'Банк уроков предмета. Урок состоит из работ и входит в курсы.', 'fs-lms' ),
			self::Works       => __( 'Банк работ предмета. Работа собирается из задач в конструкторе и входит в уроки.', 'fs-lms' ),
			self::Assessments => __( 'Банк экзаменов предмета. Экзамен — оценочное мероприятие с таймером и попытками.', 'fs-lms' ),
			self::Tasks       => __( 'Банк заданий предмета. Задание — отдельная единица контента, из которых собираются работы.', 'fs-lms' ),
			self::Articles    => __( 'Банк статей предмета. Справочные материалы для учеников.', 'fs-lms' ),
		};
	}

	/** Ключ предмета из CPT банка данного типа. */
	public function subjectFromPostType( string $post_type ): string {
		return match ( $this ) {
			self::Courses     => PostTypeResolver::subjectFromCoursePostType( $post_type ),
			self::Lessons     => PostTypeResolver::subjectFromLessonPostType( $post_type ),
			self::Works       => PostTypeResolver::subjectFromWorkPostType( $post_type ),
			self::Assessments => PostTypeResolver::subjectFromAssessmentPostType( $post_type ),
			self::Tasks       => PostTypeResolver::subjectFromTaskPostType( $post_type ),
			self::Articles    => PostTypeResolver::subjectFromArticlePostType( $post_type ),
		};
	}

	/** Определяет тип банка по CPT или null, если CPT не относится ни к одному банку. */
	public static function fromPostType( string $post_type ): ?self {
		return match ( true ) {
			PostTypeResolver::isCoursePostType( $post_type )      => self::Courses,
			PostTypeResolver::isLessonPostType( $post_type )      => self::Lessons,
			PostTypeResolver::isWorkPostType( $post_type )        => self::Works,
			PostTypeResolver::isAssessmentPostType( $post_type )  => self::Assessments,
			PostTypeResolver::isTaskPostType( $post_type )        => self::Tasks,
			PostTypeResolver::isArticlePostType( $post_type )     => self::Articles,
			default                                               => null,
		};
	}
}
