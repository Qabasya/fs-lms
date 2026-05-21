<?php

declare( strict_types=1 );

namespace Inc\Repositories;

/**
 * Class ArticleRepository
 *
 * Репозиторий для работы со статьями (тип поста {subject}_articles).
 *
 * @package Inc\Repositories
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — получение, создание, обновление и удаление статей.
 * 2. **Поиск связанных статей** — получение статей, привязанных к указанному термину таксономии.
 * 3. **Случайная выборка** — получение случайных статей для виджетов или блоков.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций (get_post, wp_insert_post, wp_update_post, wp_delete_post, WP_Query)
 * для работы со статьями. Предоставляет унифицированный интерфейс для доступа к данным статей.
 */
class ArticleRepository {

	/**
	 * Конструктор репозитория.
	 */
	public function __construct() {}

	/**
	 * Получает статью по ID.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return \WP_Post|null
	 */
	public function get( int $post_id ): ?\WP_Post {
		// get_post() — WordPress-функция для получения объекта поста
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Создаёт новую статью.
	 *
	 * @param array $data Данные поста (post_title, post_content, post_type и т.д.)
	 *
	 * @return int ID созданного поста или 0 при ошибке
	 */
	public function create( array $data ): int {
		// wp_insert_post() — создаёт пост, возвращает ID или WP_Error
		$post = wp_insert_post( $data );

		// is_wp_error() — проверяет, является ли результат ошибкой WordPress
		return is_wp_error( $post ) ? 0 : (int) $post;
	}

	/**
	 * Обновляет существующую статью.
	 *
	 * @param array $data Данные поста (должен содержать ключ 'ID')
	 *
	 * @return bool
	 */
	public function update( array $data ): bool {
		// Проверка наличия ID поста
		if ( empty( $data['ID'] ) ) {
			return false;
		}

		// wp_update_post() — обновляет пост, возвращает ID или WP_Error
		// Второй параметр true — возвращать WP_Error при ошибке
		$result = wp_update_post( $data, true );
		return ! is_wp_error( $result );
	}

	/**
	 * Удаляет статью.
	 *
	 * @param int  $post_id ID поста
	 * @param bool $force   Полное удаление (без перемещения в корзину)
	 *
	 * @return bool
	 */
	public function delete( int $post_id, bool $force = false ): bool {
		// wp_delete_post() — удаляет пост, возвращает объект WP_Post или false
		return (bool) wp_delete_post( $post_id, $force );
	}

	/**
	 * Находит статьи, связанные с указанным термином таксономии.
	 *
	 * @param string $post_type Тип поста (например, 'math_articles')
	 * @param int    $term_id   ID термина таксономии
	 * @param string $taxonomy  Слаг таксономии (например, 'math_task_number')
	 *
	 * @return \WP_Post[] Массив постов
	 */
	public function findRelated( string $post_type, int $term_id, string $taxonomy ): array {
		// WP_Query — основной класс запросов WordPress
		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 4,              // Ограничиваем 4 статьями
				'no_found_rows'  => true,           // Оптимизация (не нужна пагинация)
			// tax_query — фильтрация по таксономии
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Находит случайные статьи указанного типа.
	 *
	 * @param string $post_type Тип поста (например, 'math_articles')
	 *
	 * @return \WP_Post[] Массив постов
	 */
	public function findRandom( string $post_type ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 4,              // Ограничиваем 4 статьями
				'no_found_rows'  => true,           // Оптимизация
				'orderby'        => 'rand',         // Случайный порядок
			)
		);

		return $query->posts;
	}
}
