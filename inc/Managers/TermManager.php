<?php

declare( strict_types=1 );

namespace Inc\Managers;

/**
 * Class TermManager
 *
 * Менеджер для работы с терминами таксономий WordPress.
 * Инкапсулирует базовые операции: получение, создание, удаление терминов,
 * а также привязку терминов к постам.
 *
 * @package Inc\Managers
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
		$ids = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

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
		if ( ! taxonomy_exists( $taxonomy ) ) {
			// Регистрируем с минимальными параметрами для возможности вставки терминов
			register_taxonomy( $taxonomy, array() );
		}
	}

	/**
	 * Создаёт термин, если его ещё нет.
	 *
	 * @param string $name     Название термина
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $args     Дополнительные аргументы (slug, description, parent и т.д.)
	 *
	 * @return void
	 */
	public function insert( string $name, string $taxonomy, array $args = array() ): void {
		if ( ! $this->exists( $name, $taxonomy ) ) {
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
		$slugs = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );

		return is_wp_error( $slugs ) ? array() : (array) $slugs;
	}
	
	
	/**
	 * Привязывает термины к посту (принимает ID или слаги).
	 */
	public function setPostTerms( int $post_id, array $terms, string $taxonomy ): void {
		// WP переварит и массив ID, и массив слагов
		wp_set_post_terms( $post_id, $terms, $taxonomy );}
	
	/**
	 * Получает объект термина по ID и таксономии.
	 */
	public function get( int $term_id, string $taxonomy ): ?\WP_Term {
		$term = get_term( $term_id, $taxonomy );
		return ( $term instanceof \WP_Term ) ? $term : null;
	}

}
