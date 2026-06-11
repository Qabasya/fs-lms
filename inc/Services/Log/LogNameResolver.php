<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

/**
 * Class LogNameResolver
 *
 * Резолвит технические идентификаторы в читаемые имена для шаблонов логов.
 *
 * @package Inc\Services\Log
 *
 * ### Основные обязанности:
 *
 * 1. **Преобразование ID в имена** — получение человекочитаемых названий из технических ID.
 * 2. **Форматирование дат** — преобразование MySQL datetime в локальный формат.
 *
 * ### Архитектурная роль:
 *
 * Используется во всех 8 шаблонах логов (entity_audit, deletion, export и т.д.)
 * для отображения понятных названий вместо технических ID.
 * Класс содержит только статические методы — не инжектируется через DI.
 *
 * ### Методы:
 *
 * - userName() — ID пользователя → отображаемое имя
 * - userNameWithRole() — ID пользователя → "Имя (роль)"
 * - personName() — ID лица → ФИО
 * - postTitle() — ID поста → заголовок
 * - entityName() — универсальный метод для различных типов сущностей
 * - date() — форматирование даты
 */
class LogNameResolver {

	/**
	 * Преобразует ID пользователя в отображаемое имя.
	 * При 0/null возвращает '—'.
	 *
	 * @param int|null $userId ID пользователя WordPress
	 *
	 * @return string
	 */
	public static function userName( ?int $userId ): string {
		if ( ! $userId ) {
			return '—';
		}
		$user = get_userdata( $userId );
		return $user ? esc_html( $user->display_name ) : '#' . $userId;
	}

	/**
	 * Преобразует ID пользователя в строку "Имя (роль)".
	 *
	 * @param int|null    $userId ID пользователя WordPress
	 * @param string|null $role   Роль пользователя (опционально)
	 *
	 * @return string
	 */
	public static function userNameWithRole( ?int $userId, ?string $role = null ): string {
		$name = self::userName( $userId );
		if ( $role ) {
			return $name . ' <code>' . esc_html( $role ) . '</code>';
		}
		return $name;
	}

	/**
	 * Преобразует ID лица (из persons) в ФИО.
	 * Ищет через мета-поле fs_lms_person_id пользователя WordPress.
	 * Если пользователь не найден — падает в таблицу persons.
	 *
	 * @param int|null $personId ID лица (из таблицы persons)
	 *
	 * @return string
	 */
	public static function personName( ?int $personId ): string {
		if ( ! $personId ) {
			return '—';
		}

		global $wpdb;
		// Поиск пользователя, связанного с этим лицом
		$wpUserId = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'fs_lms_person_id' AND meta_value = %s LIMIT 1",
			(string) $personId
		) );

		if ( $wpUserId ) {
			$user = get_userdata( (int) $wpUserId );
			if ( $user ) {
				return esc_html( $user->display_name );
			}
		}

		// Fallback: запрос к таблице persons
		$name = $wpdb->get_var( $wpdb->prepare(
			"SELECT CONCAT(last_name, ' ', first_name) FROM {$wpdb->prefix}fs_lms_persons WHERE id = %d LIMIT 1",
			$personId
		) );

		return $name ? esc_html( $name ) : 'Person #' . $personId;
	}

	/**
	 * Преобразует ID поста в заголовок.
	 *
	 * @param int|null $postId ID поста WordPress
	 *
	 * @return string
	 */
	public static function postTitle( ?int $postId ): string {
		if ( ! $postId ) {
			return '—';
		}
		$title = get_the_title( $postId );
		return $title ? esc_html( $title ) : '#' . $postId;
	}

	/**
	 * Универсальный метод для получения читаемого названия сущности.
	 *
	 * @param int|null    $entityId   ID сущности
	 * @param string      $entityType Тип сущности (task, article, group, student, parent, teacher)
	 * @param string|null $oldLabel   Старое название (для сущностей на основе опций)
	 *
	 * @return string
	 */
	public static function entityName( ?int $entityId, string $entityType, ?string $oldLabel = null ): string {
		if ( ! $entityId ) {
			return $oldLabel ? esc_html( $oldLabel ) : '—';
		}

		// CPT: задания и статьи
		if ( in_array( $entityType, array( 'task', 'article' ), true ) ) {
			return self::postTitle( $entityId );
		}

		// Группы
		if ( 'group' === $entityType ) {
			global $wpdb;
			$name = $wpdb->get_var( $wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}fs_lms_groups WHERE id = %d LIMIT 1",
				$entityId
			) );
			return $name ? esc_html( $name ) : ( $oldLabel ? esc_html( $oldLabel ) : '#' . $entityId );
		}

		// Лица: студенты, родители, учителя
		if ( in_array( $entityType, array( 'student', 'parent', 'teacher' ), true ) ) {
			return self::personName( $entityId );
		}

		// Сущности на основе опций (предметы, таксономии, boilerplate, периоды)
		return $oldLabel ? esc_html( $oldLabel ) : '#' . $entityId;
	}

	/**
	 * Форматирует дату из MySQL в локальный формат (день.месяц.год часы:минуты).
	 *
	 * @param string|null $mysqlDate Дата в формате MySQL datetime
	 *
	 * @return string
	 */
	public static function date( ?string $mysqlDate ): string {
		if ( ! $mysqlDate ) {
			return '—';
		}
		// wp_date() — форматирует дату с учётом timezone WordPress
		return (string) wp_date( 'd.m.Y H:i', strtotime( $mysqlDate ) );
	}
}