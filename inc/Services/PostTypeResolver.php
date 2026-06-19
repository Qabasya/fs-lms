<?php

declare( strict_types=1 );

namespace Inc\Services;

/**
 * Резолвер типов записей предмета.
 *
 * Формирует CPT slug для заданий и статей по ключу предмета, а также извлекает
 * ключ предмета из task CPT.
 *
 * @package Inc\Services
 */
class PostTypeResolver {
	/**
	 * Суффикс CPT заданий.
	 */
	public const string TASKS_SUFFIX = '_tasks';

	/**
	 * Суффикс CPT статей.
	 */
	public const string ARTICLES_SUFFIX = '_articles';

	/**
	 * Суффикс CPT уроков.
	 */
	public const string LESSONS_SUFFIX = '_lessons';

	/**
	 * Суффикс CPT работ.
	 */
	public const string WORKS_SUFFIX = '_works';

	/**
	 * Суффикс CPT курсов.
	 */
	public const string COURSES_SUFFIX = '_courses';

	/**
	 * Суффикс CPT контрольных / экзаменов.
	 */
	public const string ASSESSMENTS_SUFFIX = '_assessments';

	/**
	 * Суффикс таксономии типов заданий.
	 */
	public const string TASK_NUMBER_SUFFIX = '_task_number';

	/**
	 * Глобальный CPT приватных задач (не per-subject).
	 */
	public const string PROBLEMS_CPT = 'fs_lms_problems';

	/**
	 * Возвращает CPT заданий указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string CPT заданий.
	 */
	public static function tasks( string $subject_key ): string {
		return $subject_key . self::TASKS_SUFFIX;
	}

	/**
	 * Возвращает CPT статей указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string CPT статей.
	 */
	public static function articles( string $subject_key ): string {
		return $subject_key . self::ARTICLES_SUFFIX;
	}

	/**
	 * Проверяет, является ли post type типом статей.
	 *
	 * @param string $post_type Тип записи WordPress.
	 *
	 * @return bool true, если post type оканчивается на суффикс статей.
	 */
	public static function isArticlePostType( string $post_type ): bool {
		return str_ends_with( $post_type, self::ARTICLES_SUFFIX );
	}

	/**
	 * Проверяет, является ли post type типом заданий.
	 *
	 * @param string $post_type Тип записи WordPress.
	 *
	 * @return bool true, если post type оканчивается на суффикс заданий.
	 */
	public static function isTaskPostType( string $post_type ): bool {
		return str_ends_with( $post_type, self::TASKS_SUFFIX );
	}

	/**
	 * Возвращает ключ предмета из CPT заданий.
	 *
	 * @param string $post_type CPT заданий.
	 *
	 * @return string Ключ предмета или пустая строка, если CPT не является заданиями.
	 */
	public static function subjectFromTaskPostType( string $post_type ): string {
		if ( ! self::isTaskPostType( $post_type ) ) {
			return '';
		}

		return substr( $post_type, 0, -strlen( self::TASKS_SUFFIX ) );
	}

	/**
	 * Возвращает ключ предмета из CPT статей.
	 *
	 * @param string $post_type CPT статей.
	 *
	 * @return string Ключ предмета или пустая строка, если CPT не является статьями.
	 */
	public static function subjectFromArticlePostType( string $post_type ): string {
		if ( ! self::isArticlePostType( $post_type ) ) {
			return '';
		}

		return substr( $post_type, 0, -strlen( self::ARTICLES_SUFFIX ) );
	}

	/**
	 * Возвращает CPT уроков указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string CPT уроков.
	 */
	public static function lessons( string $subject_key ): string {
		return $subject_key . self::LESSONS_SUFFIX;
	}

	/**
	 * Проверяет, является ли post type типом уроков.
	 *
	 * @param string $post_type Тип записи WordPress.
	 *
	 * @return bool true, если post type оканчивается на суффикс уроков.
	 */
	public static function isLessonPostType( string $post_type ): bool {
		return str_ends_with( $post_type, self::LESSONS_SUFFIX );
	}

	/**
	 * Возвращает ключ предмета из CPT уроков.
	 *
	 * @param string $post_type CPT уроков.
	 *
	 * @return string Ключ предмета или пустая строка.
	 */
	public static function subjectFromLessonPostType( string $post_type ): string {
		if ( ! self::isLessonPostType( $post_type ) ) {
			return '';
		}

		return substr( $post_type, 0, -strlen( self::LESSONS_SUFFIX ) );
	}

	/**
	 * Возвращает CPT работ указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string CPT работ.
	 */
	public static function works( string $subject_key ): string {
		return $subject_key . self::WORKS_SUFFIX;
	}

	/**
	 * Проверяет, является ли post type типом работ.
	 *
	 * @param string $post_type Тип записи WordPress.
	 *
	 * @return bool true, если post type оканчивается на суффикс работ.
	 */
	public static function isWorkPostType( string $post_type ): bool {
		return str_ends_with( $post_type, self::WORKS_SUFFIX );
	}

	/**
	 * Возвращает ключ предмета из CPT работ.
	 *
	 * @param string $post_type CPT работ.
	 *
	 * @return string Ключ предмета или пустая строка.
	 */
	public static function subjectFromWorkPostType( string $post_type ): string {
		if ( ! self::isWorkPostType( $post_type ) ) {
			return '';
		}

		return substr( $post_type, 0, -strlen( self::WORKS_SUFFIX ) );
	}

	/**
	 * Возвращает CPT курсов указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string CPT курсов.
	 */
	public static function courses( string $subject_key ): string {
		return $subject_key . self::COURSES_SUFFIX;
	}

	/**
	 * Проверяет, является ли post type типом курсов.
	 *
	 * @param string $post_type Тип записи WordPress.
	 *
	 * @return bool true, если post type оканчивается на суффикс курсов.
	 */
	public static function isCoursePostType( string $post_type ): bool {
		return str_ends_with( $post_type, self::COURSES_SUFFIX );
	}

	/**
	 * Возвращает ключ предмета из CPT курсов.
	 *
	 * @param string $post_type CPT курсов.
	 *
	 * @return string Ключ предмета или пустая строка.
	 */
	public static function subjectFromCoursePostType( string $post_type ): string {
		if ( ! self::isCoursePostType( $post_type ) ) {
			return '';
		}

		return substr( $post_type, 0, -strlen( self::COURSES_SUFFIX ) );
	}

	/**
	 * Возвращает слаг таксономии типов заданий для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string Слаг таксономии.
	 */
	public static function getTaskTaxonomy( string $subject_key ): string {
		return $subject_key . self::TASK_NUMBER_SUFFIX;
	}

	/**
	 * Возвращает CPT глобального банка задач.
	 */
	public static function problems(): string {
		return self::PROBLEMS_CPT;
	}

	/**
	 * Проверяет, является ли post type глобальным банком задач.
	 */
	public static function isProblemPostType( string $post_type ): bool {
		return self::PROBLEMS_CPT === $post_type;
	}

	/**
	 * Проверяет, относится ли post type к любому из банков контента.
	 */
	public static function isBankPostType( string $post_type ): bool {
		return self::isTaskPostType( $post_type )
			|| self::isWorkPostType( $post_type )
			|| self::isLessonPostType( $post_type )
			|| self::isCoursePostType( $post_type )
			|| self::isProblemPostType( $post_type )
			|| self::isAssessmentPostType( $post_type )
			|| str_ends_with( $post_type, self::ARTICLES_SUFFIX );
	}

	/**
	 * Возвращает CPT контрольных / экзаменов указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string CPT контрольных.
	 */
	public static function assessments( string $subject_key ): string {
		return $subject_key . self::ASSESSMENTS_SUFFIX;
	}

	/**
	 * Проверяет, является ли post type типом контрольных / экзаменов.
	 *
	 * @param string $post_type Тип записи WordPress.
	 *
	 * @return bool true, если post type оканчивается на суффикс контрольных.
	 */
	public static function isAssessmentPostType( string $post_type ): bool {
		return str_ends_with( $post_type, self::ASSESSMENTS_SUFFIX );
	}

	/**
	 * Возвращает ключ предмета из CPT контрольных.
	 *
	 * @param string $post_type CPT контрольных.
	 *
	 * @return string Ключ предмета или пустая строка.
	 */
	public static function subjectFromAssessmentPostType( string $post_type ): string {
		if ( ! self::isAssessmentPostType( $post_type ) ) {
			return '';
		}

		return substr( $post_type, 0, -strlen( self::ASSESSMENTS_SUFFIX ) );
	}
}
