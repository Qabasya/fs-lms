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
	 * Суффикс таксономии типов заданий.
	 */
	public const string TASK_NUMBER_SUFFIX = '_task_number';

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
	 * Возвращает слаг таксономии типов заданий для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета.
	 *
	 * @return string Слаг таксономии.
	 */
	public static function getTaskTaxonomy( string $subject_key ): string {
		return $subject_key . self::TASK_NUMBER_SUFFIX;
	}
}
