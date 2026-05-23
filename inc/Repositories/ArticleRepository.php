<?php

declare( strict_types=1 );

namespace Inc\Repositories;

/**
 * Class ArticleRepository
 *
 * Repository для работы со статьями предметов.
 *
 * Инкапсулирует CRUD-операции над WordPress-записями статей и запросы статей
 * для frontend-страницы задания.
 *
 * @package Inc\Repositories
 */
class ArticleRepository {
	/**
	 * Возвращает все статьи.
	 *
	 * Для статей post_type зависит от предмета, поэтому общий readAll() без
	 * параметров не используется. Для выборок применяются findRelated() и findRandom().
	 *
	 * @return array Пустой массив.
	 */
	public function readAll(): array {
		return array();
	}

	/**
	 * Получает статью по ID.
	 *
	 * @param int $post_id ID статьи.
	 *
	 * @return \WP_Post|null Объект статьи или null, если запись не найдена.
	 */
	public function get( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Создает новую статью.
	 *
	 * @param array $data Данные статьи для wp_insert_post().
	 *
	 * @return int ID созданной статьи или 0 при ошибке.
	 */
	public function create( array $data ): int {
		$post = wp_insert_post( $data );

		return is_wp_error( $post ) ? 0 : (int) $post;
	}

	/**
	 * Обновляет статью.
	 *
	 * @param array $data Данные статьи для wp_update_post().
	 *
	 * @return bool true, если статья обновлена без ошибки.
	 */
	public function update( array $data ): bool {
		if ( empty( $data['ID'] ) ) {
			return false;
		}

		$result = wp_update_post( $data, true );

		return ! is_wp_error( $result );
	}

	/**
	 * Удаляет статью.
	 *
	 * @param array $data Данные удаления статьи.
	 *
	 * @return bool true, если статья удалена или перемещена в корзину.
	 */
	public function delete( array $data ): bool {
		$post_id = (int) ( $data['ID'] ?? 0 );
		$force   = (bool) ( $data['force'] ?? false );

		if ( ! $post_id ) {
			return false;
		}

		return (bool) wp_delete_post( $post_id, $force );
	}

	/**
	 * Возвращает статьи, связанные с термином таксономии.
	 *
	 * @param string $post_type Тип записей статей.
	 * @param int    $term_id   ID термина.
	 * @param string $taxonomy  Слаг таксономии.
	 *
	 * @return \WP_Post[] Список связанных статей.
	 */
	public function findRelated( string $post_type, int $term_id, string $taxonomy ): array {
		$query = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		) );

		return $query->posts;
	}

	/**
	 * Возвращает случайные статьи указанного типа записи.
	 *
	 * @param string $post_type Тип записей статей.
	 *
	 * @return \WP_Post[] Список случайных статей.
	 */
	public function findRandom( string $post_type ): array {
		$query = new \WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'no_found_rows'  => true,
			'orderby'        => 'rand',
		) );

		return $query->posts;
	}
}
