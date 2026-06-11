<?php

declare( strict_types=1 );

namespace Inc\Services\Log;

/**
 * Резолвит технические идентификаторы в читаемые имена для шаблонов логов.
 * Используется во всех 8 шаблонах логов. Не инжектируется — только статические вызовы.
 */
class LogNameResolver {

	/**
	 * user_id → display_name; при 0/null → '—'.
	 */
	public static function userName( ?int $userId ): string {
		if ( ! $userId ) {
			return '—';
		}
		$user = get_userdata( $userId );
		return $user ? esc_html( $user->display_name ) : '#' . $userId;
	}

	/**
	 * user_id → "Имя (role)".
	 */
	public static function userNameWithRole( ?int $userId, ?string $role = null ): string {
		$name = self::userName( $userId );
		if ( $role ) {
			return $name . ' <code>' . esc_html( $role ) . '</code>';
		}
		return $name;
	}

	/**
	 * person_id → ФИО через persons таблицу (через WP user meta fs_lms_person_id).
	 * Если user не найден — возвращает "Person #ID".
	 */
	public static function personName( ?int $personId ): string {
		if ( ! $personId ) {
			return '—';
		}

		global $wpdb;
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

		// Fallback: persons table
		$name = $wpdb->get_var( $wpdb->prepare(
			"SELECT CONCAT(last_name, ' ', first_name) FROM {$wpdb->prefix}fs_lms_persons WHERE id = %d LIMIT 1",
			$personId
		) );

		return $name ? esc_html( $name ) : 'Person #' . $personId;
	}

	/**
	 * post_id → заголовок поста.
	 */
	public static function postTitle( ?int $postId ): string {
		if ( ! $postId ) {
			return '—';
		}
		$title = get_the_title( $postId );
		return $title ? esc_html( $title ) : '#' . $postId;
	}

	/**
	 * entity_id + entity_type → читаемое название сущности.
	 * Для CPT (task/article) — заголовок поста.
	 * Для остальных (group) — имя из БД если доступно.
	 * Для options-based сущностей (subject/taxonomy/boilerplate/period) — old_label.
	 */
	public static function entityName( ?int $entityId, string $entityType, ?string $oldLabel = null ): string {
		if ( ! $entityId ) {
			return $oldLabel ? esc_html( $oldLabel ) : '—';
		}

		if ( in_array( $entityType, array( 'task', 'article' ), true ) ) {
			return self::postTitle( $entityId );
		}

		if ( 'group' === $entityType ) {
			global $wpdb;
			$name = $wpdb->get_var( $wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}fs_lms_groups WHERE id = %d LIMIT 1",
				$entityId
			) );
			return $name ? esc_html( $name ) : ( $oldLabel ? esc_html( $oldLabel ) : '#' . $entityId );
		}

		if ( in_array( $entityType, array( 'student', 'parent', 'teacher' ), true ) ) {
			return self::personName( $entityId );
		}

		return $oldLabel ? esc_html( $oldLabel ) : '#' . $entityId;
	}

	/**
	 * Форматирует дату из MySQL в локальный формат.
	 */
	public static function date( ?string $mysqlDate ): string {
		if ( ! $mysqlDate ) {
			return '—';
		}
		return (string) wp_date( 'd.m.Y H:i', strtotime( $mysqlDate ) );
	}
}
