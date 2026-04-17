<?php

declare( strict_types=1 );

namespace Inc\Managers;

/**
 * Class PostManager
 *
 * Менеджер для работы с постами WordPress.
 * Инкапсулирует базовые операции: получение, создание, удаление постов,
 * а также работу с мета-полями.
 *
 * @package Inc\Managers
 */
class PostManager {
	/**
	 * Возвращает массив ID постов указанного типа.
	 *
	 * @param string $post_type Тип поста (например, "math_tasks")
	 *
	 * @return int[] Массив ID постов
	 */
	public function getIds( string $post_type ): array {
		return get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => - 1,      // Получить все посты
				'post_status' => 'any',    // Все статусы (опубликованные, черновики, корзина)
				'fields'      => 'ids',    // Возвращать только ID
			)
		);
	}

	/**
	 * Возвращает массив объектов WP_Post указанного типа.
	 *
	 * @param string $post_type Тип поста (например, "math_tasks")
	 *
	 * @return \WP_Post[] Массив объектов постов
	 */
	public function getAll( string $post_type ): array {
		return get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => - 1,      // Получить все посты
				'post_status' => 'any',    // Все статусы
			)
		);
	}

	/**
	 * Удаляет пост полностью (включая корзину).
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function delete( int $post_id ): void {
		// true — полное удаление без перемещения в корзину
		wp_delete_post( $post_id, true );
	}

	/**
	 * Удаляет все посты указанного типа.
	 *
	 * @param string $post_type Тип поста
	 *
	 * @return void
	 */
	public function deleteAll( string $post_type ): void {
		foreach ( $this->getIds( $post_type ) as $id ) {
			$this->delete( (int) $id );
		}
	}

	/**
	 * Считает посты любого статуса, привязанные к термину таксономии.
	 *
	 * @param string $post_type Тип поста
	 * @param string $taxonomy  Слаг таксономии
	 * @param int    $term_id   ID термина
	 *
	 * @return int Количество постов
	 */
	public function countByTerm( string $post_type, string $taxonomy, int $term_id ): int {
		return count(
			get_posts(
				array(
					'post_type'   => $post_type,
					'numberposts' => - 1,
					'post_status' => 'any',
					'fields'      => 'ids',
					'tax_query'   => array(
						array(
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => $term_id,
						),
					),
				)
			)
		);
	}

	/**
	 * Строит WP_Posts_List_Table для указанного CPT и вкладки, переопределяя REQUEST_URI.
	 *
	 * @param string $post_type CPT slug (например, "math_tasks")
	 * @param string $page      Значение GET-параметра page
	 * @param string $tab       Слаг вкладки (например, "tab-2")
	 *
	 * @return \Inc\DTO\PostsListTableDTO
	 */
	public function buildListTable( string $post_type, string $page, string $tab ): \Inc\DTO\PostsListTableDTO {
		if ( ! class_exists( 'WP_Posts_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		}

		set_current_screen( 'edit-' . $post_type );
		$table = _get_list_table( 'WP_Posts_List_Table', array( 'screen' => 'edit-' . $post_type ) );

		$edit_base   = admin_url( 'edit.php?post_type=' . $post_type );
		$custom_base = admin_url( 'admin.php?page=' . $page . '&tab=' . $tab );

		$uri_args = array(
			'page' => $page,
			'tab'  => $tab,
		);
		if ( ! empty( $_GET['post_status'] ) ) {
			$uri_args['post_status'] = sanitize_key( $_GET['post_status'] );
		}

		$original_uri           = $_SERVER['REQUEST_URI'];
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?' . http_build_query( $uri_args );

		$_GET['post_type'] = $post_type;
		$table->prepare_items();

		return new \Inc\DTO\PostsListTableDTO(
			table           : $table,
			post_type_object: get_post_type_object( $post_type ),
			post_type       : $post_type,
			edit_base       : $edit_base,
			custom_base     : $custom_base,
			original_uri    : $original_uri,
			tab             : $tab,
			page_slug       : $page,
		);
	}

	/**
	 * Создаёт новый пост.
	 *
	 * @param array $data Данные поста (post_title, post_content, post_type и т.д.)
	 *
	 * @return int ID созданного поста или 0 при ошибке
	 */
	public function insert( array $data ): int {
		$id = wp_insert_post( $data );

		// При ошибке wp_insert_post возвращает WP_Error
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Возвращает все мета-поля поста в виде ассоциативного массива.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return array<string, mixed> Массив мета-данных [meta_key => meta_value]
	 */
	public function getAllMeta( int $post_id ): array {
		$raw    = get_post_meta( $post_id );
		$result = array();

		foreach ( $raw as $key => $_ ) {
			// get_post_meta с true возвращает одно значение (не массив)
			$result[ $key ] = get_post_meta( $post_id, $key, true );
		}

		return $result;
	}

	/**
	 * Обновляет мета-поле поста.
	 *
	 * @param int    $post_id ID поста
	 * @param string $key     Ключ мета-поля
	 * @param mixed  $value   Значение мета-поля
	 *
	 * @return void
	 */
	public function updateMeta( int $post_id, string $key, mixed $value ): void {
		update_post_meta( $post_id, $key, $value );
	}
}
