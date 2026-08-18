<?php

declare( strict_types=1 );

namespace Inc\Managers\Wp;

use Inc\Enums\Wp\PostMetaName;

/**
 * Class TermManager
 *
 * Менеджер для работы с терминами таксономий WordPress.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — получение, создание, удаление терминов таксономий.
 * 2. **Массовые операции** — удаление всех терминов таксономии.
 * 3. **Привязка к постам** — установка терминов для поста, получение слагов привязанных терминов.
 * 4. **Управление таксономиями** — проверка существования и регистрация таксономий.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций (get_terms, wp_insert_term, wp_set_post_terms),
 * предоставляя унифицированный интерфейс для работы с терминами в плагине.
 */
class TermManager {

	/**
	 * Возвращает массив ID терминов указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return int[] Массив ID терминов
	 */
	/**
	 * Термины «коллекций» (всех пользовательских таксономий типа записи,
	 * кроме исключённой), как карта term_id => name.
	 *
	 * @param string $post_type         Тип записи.
	 * @param string $exclude_taxonomy  Таксономия, которую не включать (напр. фикс. «номера заданий»).
	 *
	 * @return array<int, string>
	 */
	public function listCollections( string $post_type, string $exclude_taxonomy ): array {
		$result = array();

		foreach ( get_object_taxonomies( $post_type ) as $tax_slug ) {
			if ( $tax_slug === $exclude_taxonomy ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax_slug, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$result[ $term->term_id ] = $term->name;
			}
		}

		return $result;
	}

	public function getIds( string $taxonomy ): array {
		// get_terms() — возвращает массив терминов по параметрам
		// 'hide_empty' => false — включать термины без постов
		// 'fields' => 'ids' — возвращать только ID
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		// is_wp_error() — проверка на ошибку WordPress
		return is_wp_error( $ids ) ? array() : (array) $ids;
	}

	/**
	 * Возвращает массив объектов терминов указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return \WP_Term[] Массив объектов терминов
	 */
	public function getAll( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		return is_wp_error( $terms ) ? array() : (array) $terms;
	}

	/**
	 * Удаляет термин по ID.
	 *
	 * @param int    $term_id  ID термина
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function delete( int $term_id, string $taxonomy ): void {
		// wp_delete_term() — удаляет термин и его связи с постами
		wp_delete_term( $term_id, $taxonomy );
	}

	/**
	 * Удаляет все термины указанной таксономии.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function deleteAll( string $taxonomy ): void {
		foreach ( $this->getIds( $taxonomy ) as $id ) {
			$this->delete( (int) $id, $taxonomy );
		}
	}

	/**
	 * Проверяет существование термина по названию.
	 *
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return bool true, если термин существует
	 */
	public function exists( string $name, string $taxonomy ): bool {
		// term_exists() — возвращает ID термина или false
		return (bool) term_exists( $name, $taxonomy );
	}

	/**
	 * Возвращает ID термина по названию, создавая его при отсутствии (в отличие
	 * от {@see insert()}, который для уже существующего термина отдаёт 0).
	 *
	 * @param string $name     Название термина (напр. номер задания)
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return int ID термина; 0 — если создать не удалось
	 */
	public function getOrCreateIdByName( string $name, string $taxonomy ): int {
		// term_exists() возвращает term_id (термин без иерархии) или массив ['term_id' => ..., 'term_taxonomy_id' => ...]
		$existing = term_exists( $name, $taxonomy );
		if ( is_array( $existing ) ) {
			return (int) ( $existing['term_id'] ?? 0 );
		}
		if ( is_numeric( $existing ) ) {
			return (int) $existing;
		}

		$result = wp_insert_term( $name, $taxonomy );
		return is_wp_error( $result ) ? 0 : (int) ( $result['term_id'] ?? 0 );
	}

	/**
	 * Регистрирует таксономию, если она ещё не существует.
	 *
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function ensureTaxonomy( string $taxonomy ): void {
		// taxonomy_exists() — проверяет, зарегистрирована ли таксономия
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// register_taxonomy() — регистрирует новую таксономию
			// Второй параметр — массив типов постов (пустой для минимальной регистрации)
			register_taxonomy( $taxonomy, array() );
		}
	}

	/**
	 * Создаёт термин, если его ещё нет.
	 *
	 * Возвращает ID **только для реально созданного** термина: вызывающий код
	 * (импорт предмета/пакета) обязан уметь откатить лишь то, что создал сам,
	 * и не трогать уже существовавшие термины.
	 *
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $args     Дополнительные аргументы (slug, description, parent)
	 *
	 * @return int ID созданного термина; 0 — если термин уже был или вставка не удалась
	 */
	public function insert( string $name, string $taxonomy, array $args = array() ): int {
		if ( $this->exists( $name, $taxonomy ) ) {
			return 0;
		}

		// wp_insert_term() — создаёт термин в базе данных
		$result = wp_insert_term( $name, $taxonomy, $args );

		return is_wp_error( $result ) ? 0 : (int) ( $result['term_id'] ?? 0 );
	}

	/**
	 * Возвращает массив слагов терминов, привязанных к посту.
	 *
	 * @param int    $post_id  ID поста
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return string[] Массив слагов терминов
	 */
	public function getPostSlugs( int $post_id, string $taxonomy ): array {
		// wp_get_post_terms() — возвращает термины поста
		// 'fields' => 'slugs' — возвращать только слаги
		$slugs = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );

		return is_wp_error( $slugs ) ? array() : (array) $slugs;
	}

	/**
	 * Привязывает термины к посту.
	 *
	 * @param int    $post_id  ID поста
	 * @param array  $terms    Массив ID или слагов терминов
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return void
	 */
	public function setPostTerms( int $post_id, array $terms, string $taxonomy ): void {
		// wp_set_post_terms() — устанавливает термины для поста
		// Умеет работать как с ID, так и со слагами
		wp_set_post_terms( $post_id, $terms, $taxonomy );
	}

	/**
	 * Получает объект термина по ID и таксономии.
	 *
	 * @param int    $term_id  ID термина
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return \WP_Term|null
	 */
	public function get( int $term_id, string $taxonomy ): ?\WP_Term {
		// get_term() — возвращает объект термина или null
		$term = get_term( $term_id, $taxonomy );
		return ( $term instanceof \WP_Term ) ? $term : null;
	}

	/**
	 * Возвращает массив объектов терминов, привязанных к посту.
	 *
	 * @param int    $post_id  ID поста
	 * @param string $taxonomy Слаг таксономии
	 *
	 * @return \WP_Term[]
	 */
	public function getPostTerms( int $post_id, string $taxonomy ): array {
		$terms = get_the_terms( $post_id, $taxonomy );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms : array();
	}

	/**
	 * Возвращает URL термина или пустую строку при ошибке.
	 *
	 * @param int    $term_id  ID термина.
	 * @param string $taxonomy Слаг таксономии.
	 *
	 * @return string
	 */
	public function getLink( int $term_id, string $taxonomy ): string {
		$link = get_term_link( $term_id, $taxonomy );
		return is_wp_error( $link ) ? '' : $link;
	}

	/**
	 * Счётчики терминов таксономии в разрезе ОДНОГО типа записи.
	 *
	 * `$term->count` из get_terms() считает все типы записей, привязанные к таксономии
	 * (напр. `{key}_task_number` зарегистрирована и для заданий, и для статей), поэтому
	 * для фильтров страницы «Все задания» он завышен. Здесь — прямой сгруппированный
	 * запрос по связям с ограничением на post_type и статус.
	 *
	 * @param string $taxonomy    Слаг таксономии.
	 * @param string $post_type   Тип записи, который считаем.
	 * @param string $post_status Статус записей (по умолчанию только опубликованные).
	 *
	 * @return array<int, int> Карта term_id => количество записей.
	 */
	public function countPostsByType( string $taxonomy, string $post_type, string $post_status = 'publish' ): array {
		global $wpdb;

		// Дочерние задания связки (19/20/21, PostMetaName::TaskBundleParentId) на витрине
		// не показываются — исключаем их из счётчиков теми же условиями, что и список.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS term_id, COUNT( p.ID ) AS total
				 FROM {$wpdb->term_taxonomy} tt
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = %s
				 AND NOT EXISTS (
				     SELECT 1 FROM {$wpdb->postmeta} pm
				     WHERE pm.post_id = p.ID AND pm.meta_key = %s
				 )
				 GROUP BY tt.term_id",
				$taxonomy,
				$post_type,
				$post_status,
				PostMetaName::TaskBundleParentId->value
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['term_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Счётчики терминов таксономии в пределах заданного списка записей.
	 *
	 * Нужен для фасетных фильтров: список ID приходит из запроса, уже суженного
	 * другими активными фильтрами, поэтому ограничений по типу/статусу здесь нет.
	 *
	 * @param string $taxonomy Слаг таксономии.
	 * @param array  $post_ids ID записей, среди которых считаем.
	 *
	 * @return array<int, int> Карта term_id => количество записей.
	 */
	public function countPostsByIds( string $taxonomy, array $post_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS term_id, COUNT( tr.object_id ) AS total
				 FROM {$wpdb->term_taxonomy} tt
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				 WHERE tt.taxonomy = %s AND tr.object_id IN ( {$placeholders} )
				 GROUP BY tt.term_id",
				array_merge( array( $taxonomy ), $ids )
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row['term_id'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
