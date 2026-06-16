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

		// Fallback: запрос к таблице persons (CONCAT_WS пропускает NULL-значения)
		$name = $wpdb->get_var( $wpdb->prepare(
			"SELECT CONCAT_WS(' ', last_name, first_name, middle_name) FROM {$wpdb->prefix}fs_lms_persons WHERE id = %d LIMIT 1",
			$personId
		) );

		return ( $name && '' !== trim( $name ) ) ? esc_html( trim( $name ) ) : 'Person #' . $personId;
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
	 * Генерирует HTML содержимое <th> с сортировочной ссылкой.
	 * Переключает направление при повторном клике; сбрасывает paged=1.
	 *
	 * @param string $label   Текст заголовка
	 * @param string $column  Поле сортировки ('id' или 'created_at')
	 * @param string $current Текущее поле сортировки
	 * @param string $order   Текущее направление ('asc' или 'desc')
	 * @param string $baseUrl URL с уже примёнёнными фильтрами
	 *
	 * @return string
	 */
	public static function sortableHeader( string $label, string $column, string $current, string $order, string $baseUrl ): string {
		$isActive  = $current === $column;
		$nextOrder = ( $isActive && 'asc' === $order ) ? 'desc' : 'asc';
		$url       = esc_url( add_query_arg( array( 'orderby' => $column, 'order' => $nextOrder, 'paged' => 1 ), $baseUrl ) );
		$indicator = $isActive ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : '';
		return '<a href="' . $url . '">' . esc_html( $label ) . $indicator . '</a>';
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

	/**
	 * Резолвит ФИО по email получателя письма.
	 * Ищет WP-пользователя по email, затем display_name. Fallback — сам email.
	 *
	 * @param string|null $email Email получателя
	 *
	 * @return string
	 */
	public static function personNameByEmail( ?string $email ): string {
		if ( ! $email ) {
			return '—';
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return esc_html( $user->display_name );
		}
		return esc_html( $email );
	}

	/**
	 * Резолвит ФИО по логину или email (для журнала аутентификации).
	 * Ищет WP-пользователя по login, затем по email. Fallback — исходный идентификатор.
	 *
	 * @param string|null $identifier Логин или email
	 *
	 * @return string
	 */
	public static function personNameByLogin( ?string $identifier ): string {
		if ( ! $identifier ) {
			return '—';
		}
		$user = get_user_by( 'login', $identifier );
		if ( ! $user ) {
			$user = get_user_by( 'email', $identifier );
		}
		return $user ? esc_html( $user->display_name ) : esc_html( $identifier );
	}

	/**
	 * Резолвит JSON-массив ID целей экспорта в человекочитаемые имена (ФИО / названия групп).
	 *
	 * Возвращает СЫРУЮ строку (не экранированную) — для HTML обернуть в esc_html(),
	 * для CSV использовать как есть.
	 *
	 * @param string      $dataType      Тип данных (students, parents, groups, archive, log_*)
	 * @param string|null $targetIdsJson JSON-массив ID
	 * @param int         $limit         Сколько имён показать; 0 — все. При превышении добавляется « …»
	 *
	 * @return string
	 */
	public static function exportTargets( string $dataType, ?string $targetIdsJson, int $limit = 0 ): string {
		if ( empty( $targetIdsJson ) ) {
			return '—';
		}

		$ids = json_decode( $targetIdsJson, true );
		$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
		if ( empty( $ids ) ) {
			return '—';
		}

		$names = self::resolveTargetNames( $dataType, $ids );
		if ( empty( $names ) ) {
			// Тип без именованных целей (например, экспорт журналов) — показываем сами ID.
			return implode( ', ', $ids );
		}

		$total = count( $names );
		if ( $limit > 0 && $total > $limit ) {
			return implode( ', ', array_slice( $names, 0, $limit ) ) . ' …';
		}

		return implode( ', ', $names );
	}

	/**
	 * Возвращает имена целей в порядке переданных ID (с fallback «#id»).
	 *
	 * @param string $dataType Тип данных
	 * @param int[]  $ids      ID целей
	 *
	 * @return string[] Пустой массив для типов без именованных целей (логи)
	 */
	private static function resolveTargetNames( string $dataType, array $ids ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// SQL по типу данных: студенты/родители → persons, архив → снапшот student_records, группы → groups.
		if ( 'groups' === $dataType ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name AS nm FROM {$wpdb->prefix}fs_lms_groups WHERE id IN ($placeholders)",
				$ids
			), OBJECT_K );
		} elseif ( 'archive' === $dataType ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, CONCAT_WS(' ', snapshot_last_name, snapshot_first_name, snapshot_middle_name) AS nm FROM {$wpdb->prefix}fs_lms_student_records WHERE id IN ($placeholders)",
				$ids
			), OBJECT_K );
		} elseif ( in_array( $dataType, array( 'students', 'parents' ), true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, CONCAT_WS(' ', last_name, first_name, middle_name) AS nm FROM {$wpdb->prefix}fs_lms_persons WHERE id IN ($placeholders)",
				$ids
			), OBJECT_K );
		} else {
			return array(); // log_* и прочие типы без именованных целей
		}

		$names = array();
		foreach ( $ids as $id ) {
			$nm        = isset( $rows[ $id ] ) ? trim( (string) $rows[ $id ]->nm ) : '';
			$names[]   = '' !== $nm ? $nm : '#' . $id;
		}

		return $names;
	}
}