<?php

declare( strict_types=1 );

namespace Inc\Managers;

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
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $args     Дополнительные аргументы (slug, description, parent)
	 *
	 * @return void
	 */
	public function insert( string $name, string $taxonomy, array $args = array() ): void {
		if ( ! $this->exists( $name, $taxonomy ) ) {
			// wp_insert_term() — создаёт термин в базе данных
			wp_insert_term( $name, $taxonomy, $args );
		}
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
}
