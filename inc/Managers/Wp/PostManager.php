<?php

declare( strict_types=1 );

namespace Inc\Managers\Wp;

/**
 * Class PostManager
 *
 * Менеджер для работы с постами WordPress.
 *
 * @package Inc\Managers
 *
 * ### Основные обязанности:
 *
 * 1. **CRUD-операции** — создание, чтение, удаление постов и мета-полей.
 * 2. **Массовые операции** — удаление всех постов типа, получение ID или объектов.
 * 3. **Таблицы постов** — построение WP_Posts_List_Table для админ-интерфейсов.
 * 4. **Запросы по таксономиям** — получение постов по термину или последних записей.
 *
 * ### Архитектурная роль:
 *
 * Инкапсулирует вызовы WordPress-функций (get_posts, wp_insert_post, get_post_meta),
 * предоставляя унифицированный интерфейс для работы с постами в плагине.
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
		// get_posts() — возвращает массив постов по параметрам
		// 'numberposts' => -1 — получить все посты без ограничения
		// 'post_status' => явный список — 'any' НЕ включает trash/auto-draft, из-за чего
		// эти посты переживают deleteAll() и остаются мусором в wp_posts
		// 'fields' => 'ids' — возвращать только ID (экономия памяти)
		return get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => - 1,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'fs_archived', 'trash', 'auto-draft' ),
				'fields'      => 'ids',
			)
		);
	}

	/**
	 * Возвращает массив объектов WP_Post указанного типа.
	 *
	 * @param string $post_type Тип поста
	 *
	 * @return \WP_Post[] Массив объектов постов
	 */
	public function getAll( string $post_type ): array {
		return get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => - 1,
				'post_status' => 'any',
			)
		);
	}

	/**
	 * Удаляет пост полностью (без перемещения в корзину).
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function delete( int $post_id ): void {
		// wp_delete_post(, true) — второй параметр true = полное удаление
		wp_delete_post( $post_id, true );
	}

	/**
	 * Перемещает пост в корзину.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function trash( int $post_id ): void {
		wp_trash_post( $post_id );
	}

	/**
	 * Восстанавливает пост из корзины.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return void
	 */
	public function untrash( int $post_id ): void {
		wp_untrash_post( $post_id );
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
	 * Считает посты, привязанные к термину таксономии.
	 *
	 * @param string $post_type Тип поста
	 * @param string $taxonomy  Слаг таксономии
	 * @param int    $term_id   ID термина
	 *
	 * @return int Количество постов
	 */
	public function countByTerm( string $post_type, string $taxonomy, int $term_id ): int {
		// tax_query — условие фильтрации по таксономии
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
	 * Создаёт новый пост.
	 *
	 * @param array $data Данные поста (post_title, post_content, post_type и т.д.)
	 *
	 * @return int ID созданного поста или 0 при ошибке
	 */
	public function insert( array $data ): int {
		$id = wp_insert_post( $data );

		// is_wp_error() — проверяет, является ли результат ошибкой WordPress
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Считает опубликованные записи CPT в разрезе термов таксономии.
	 *
	 * Один запрос вместо N: раньше шаблон статистики предмета строил по два
	 * `WP_Query` на КАЖДЫЙ номер задания (аудит §P3).
	 *
	 * @param string $post_type Тип записи
	 * @param string $taxonomy  Таксономия
	 *
	 * @return array<int, int> term_id => количество записей
	 */
	public function countPublishedByTerms( string $post_type, string $taxonomy ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_id AS term_id, COUNT(p.ID) AS total
				 FROM {$wpdb->term_taxonomy} tt
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				 WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'
				 GROUP BY tt.term_id",
				$taxonomy,
				$post_type
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows ?: array() as $row ) {
			$counts[ (int) $row['term_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Считает опубликованные записи типа — счётчик банка для публичных блоков.
	 *
	 * `wp_count_posts()`, а не `WP_Query`: один сгруппированный запрос вместо
	 * выборки со счётом совпадений, и результат лежит в объектном кэше.
	 *
	 * @param string $post_type Тип записи.
	 *
	 * @return int
	 */
	public function countPublished( string $post_type ): int {
		if ( '' === $post_type ) {
			return 0;
		}

		return (int) ( wp_count_posts( $post_type )->publish ?? 0 );
	}

	/**
	 * Обновляет поля поста (post_title, post_content и т.д.).
	 *
	 * @param int   $post_id ID поста
	 * @param array $data    Поля для обновления (без ID — добавляется автоматически)
	 *
	 * @return bool true при успехе
	 */
	public function update( int $post_id, array $data ): bool {
		$result = wp_update_post( array_merge( $data, array( 'ID' => $post_id ) ) );

		return ! is_wp_error( $result ) && 0 !== $result;
	}

	/**
	 * Низкоуровневое обновление post_content без запуска жизненного цикла сохранения.
	 *
	 * Пишет напрямую в таблицу, минуя wp_update_post и фильтры wp_insert_post_data
	 * (напр. валидацию обязательных таксономий, которая в неформенном контексте
	 * понижает статус). Предназначено для поддержки денормализованного поискового
	 * индекса ({@see \Inc\Services\Task\TaskSearchIndexer}).
	 *
	 * @param int    $post_id ID поста.
	 * @param string $content Новое значение post_content.
	 *
	 * @return void
	 */
	public function updatePostContent( int $post_id, string $content ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => $post_id )
		);

		clean_post_cache( $post_id );
	}

	/**
	 * Слаги записей типа, начинающиеся с заданного префикса.
	 *
	 * Нужен генератору слага статьи ({@see \Inc\Services\Subject\ArticleSlugService}):
	 * ему важны только занятые порядковые номера серии, и один запрос за слагами
	 * дешевле выборки записей с разбором объектов.
	 *
	 * Корзина исключена: ядро дописывает удалённым записям суффикс `__trashed`
	 * (wp-includes/post.php:8399), их номера свободны.
	 *
	 * @param string $post_type  Тип записи.
	 * @param string $prefix     Начало слага, например `article-task-5-`.
	 * @param int    $exclude_id Какую запись не учитывать (обычно — саму сохраняемую).
	 *
	 * @return string[] Занятые слаги.
	 */
	public function findSlugsByPrefix( string $post_type, string $prefix, int $exclude_id = 0 ): array {
		global $wpdb;

		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_name FROM {$wpdb->posts}
				 WHERE post_type = %s AND post_status <> 'trash' AND ID <> %d AND post_name LIKE %s",
				$post_type,
				$exclude_id,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return array_map( 'strval', $slugs ?: array() );
	}

	/**
	 * Низкоуровневая смена слага без запуска жизненного цикла сохранения.
	 *
	 * Пишет напрямую в таблицу — как {@see self::updatePostContent()}. Через
	 * `wp_update_post()` пакетное переименование ловило бы собственный фильтр
	 * генерации слага, плодило ревизии и двигало `post_modified`.
	 *
	 * @param int    $post_id ID записи.
	 * @param string $slug    Новый слаг (уникальность — на вызывающем коде).
	 *
	 * @return void
	 */
	public function renameSlug( int $post_id, string $slug ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->posts,
			array( 'post_name' => $slug ),
			array( 'ID' => $post_id )
		);

		clean_post_cache( $post_id );
	}

	/**
	 * Запоминает прежний слаг записи, чтобы старый адрес отдавал 301.
	 *
	 * `_wp_old_slug` — штатный механизм ядра: по нему работает
	 * `wp_old_slug_redirect()` на 404.
	 *
	 * @param int    $post_id  ID записи.
	 * @param string $old_slug Прежний слаг.
	 *
	 * @return void
	 */
	public function rememberOldSlug( int $post_id, string $old_slug ): void {
		if ( '' === $old_slug ) {
			return;
		}

		add_post_meta( $post_id, '_wp_old_slug', $old_slug );
	}

	/**
	 * Возвращает все мета-поля поста в виде ассоциативного массива.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return array<string, mixed> Массив мета-данных [meta_key => meta_value]
	 */
	public function getAllMeta( int $post_id ): array {
		// get_post_meta() без ключа возвращает все мета-поля
		$raw    = get_post_meta( $post_id );
		$result = array();

		foreach ( $raw as $key => $_ ) {
			// get_post_meta(, true) возвращает одно значение (не массив)
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

	/**
	 * Получает список постов с их терминами по конкретному ID термина.
	 *
	 * @param string $post_type          Тип поста
	 * @param string $taxonomy           Слаг таксономии для фильтрации
	 * @param int    $term_id            ID термина
	 * @param array  $visible_taxonomies Дополнительные таксономии для вывода данных
	 *
	 * @return array
	 */
	public function getPostsByTerm( string $post_type, string $taxonomy, int $term_id, array $visible_taxonomies ): array {
		// WP_Query — основной класс запросов WordPress
		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		return array_map(
			function ( \WP_Post $post ) use ( $visible_taxonomies ) {
				return array(
					'title'     => $post->post_title,
					'number'    => $post->post_name,  // slug как номер задания
					'status'    => $post->post_status,
					// get_edit_post_link() — PageRoutes для редактирования поста в админке
					'edit_link' => get_edit_post_link( $post->ID ) ?? '',
					'terms'     => $this->collectTermsData( $post->ID, $visible_taxonomies ),
				);
			},
			$query->posts
		);
	}

	/**
	 * Получает последние N постов с привязанными терминами.
	 *
	 * @param string $post_type       Тип поста
	 * @param int    $limit           Количество постов
	 * @param string $number_tax      Таксономия для номера задания
	 * @param array  $other_taxonomies Дополнительные таксономии для вывода данных
	 *
	 * @return array
	 */
	public function getRecentPosts( string $post_type, int $limit, string $number_tax, array $other_taxonomies ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			// get_the_terms() — возвращает массив объектов терминов для поста
			$numbers = get_the_terms( $post->ID, $number_tax );
			// wp_list_pluck() — извлекает поле 'name' из массива объектов
			$number_val = $numbers && ! is_wp_error( $numbers ) ? implode( ', ', wp_list_pluck( $numbers, 'name' ) ) : '—';

			$rows[] = array(
				'number'    => $number_val,
				'title'     => $post->post_title,
				'edit_link' => get_edit_post_link( $post->ID ),
				'terms'     => $this->collectTermsData( $post->ID, $other_taxonomies ),
			);
		}

		return $rows;
	}

	/**
	 * Вспомогательный метод для сбора названий терминов по списку таксономий.
	 *
	 * @param int   $post_id    ID поста
	 * @param array $taxonomies Массив таксономий (объекты или строки)
	 *
	 * @return array
	 */
	private function collectTermsData( int $post_id, array $taxonomies ): array {
		$data = array();
		foreach ( $taxonomies as $tax ) {
			$slug          = is_object( $tax ) ? $tax->slug : $tax;
			$terms         = get_the_terms( $post_id, $slug );
			$data[ $slug ] = $terms && ! is_wp_error( $terms ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
		}
		return $data;
	}

	/**
	 * Получает объект поста по ID.
	 *
	 * @param int $post_id ID поста
	 *
	 * @return \WP_Post|null
	 */
	public function get( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Возвращает соседний пост (предыдущий или следующий) относительно указанного.
	 *
	 * Непустая $taxonomy ограничивает выборку записями с ТЕМ ЖЕ термином этой
	 * таксономии (навигация внутри одного типа задания).
	 *
	 * @param int    $post_id  ID поста.
	 * @param bool   $previous true — предыдущий, false — следующий.
	 * @param string $taxonomy Слаг таксономии для «в пределах термина»; '' — по всем записям.
	 *
	 * @return \WP_Post|null
	 */
	public function getAdjacent( int $post_id, bool $previous = true, string $taxonomy = '' ): ?\WP_Post {
		global $post;
		$saved = $post;
		$post  = get_post( $post_id );
		setup_postdata( $post );
		$adjacent = '' !== $taxonomy
			? get_adjacent_post( true, '', $previous, $taxonomy )
			: get_adjacent_post( false, '', $previous );
		$post = $saved;
		wp_reset_postdata();
		return $adjacent instanceof \WP_Post ? $adjacent : null;
	}

	/**
	 * Возвращает URL миниатюры записи в нужном размере.
	 *
	 * @param int    $post_id ID поста
	 * @param string $size    Размер изображения WordPress
	 *
	 * @return string URL миниатюры; '' — обложки у записи нет.
	 */
	public function getThumbnailUrl( int $post_id, string $size = 'large' ): string {
		return (string) get_the_post_thumbnail_url( $post_id, $size );
	}

	/**
	 * Получает конкретное мета-поле поста.
	 *
	 * @param int    $post_id ID поста
	 * @param string $key     Ключ мета-поля
	 * @param bool   $single  Возвращать одно значение (true) или массив (false)
	 *
	 * @return mixed
	 */
	public function getMeta( int $post_id, string $key, bool $single = true ): mixed {
		return get_post_meta( $post_id, $key, $single );
	}

	/**
	 * Прогревает meta-кэш списка постов одним запросом (батч без N+1):
	 * последующие getMeta() по этим ID читают из object cache, а не из БД.
	 * Обёртка над `update_postmeta_cache()` (алиас `update_meta_cache('post', …)`).
	 *
	 * @param int[] $post_ids ID постов
	 *
	 * @return void
	 */
	public function primeMetaCache( array $post_ids ): void {
		$ids = array_filter( array_map( 'intval', $post_ids ) );
		if ( array() !== $ids ) {
			update_postmeta_cache( $ids );
		}
	}

	/**
	 * Гибкая выборка постов типа (для селекторов банков и read-моделей).
	 *
	 * @param string $post_type Тип записи.
	 * @param array{
	 *     status?: string|array<int, string>,
	 *     author?: int,
	 *     search?: string,
	 *     tax_query?: array,
	 *     limit?: int,
	 *     orderby?: string,
	 *     order?: string
	 * } $opts Параметры выборки.
	 *
	 * @return \WP_Post[]
	 */
	public function search( string $post_type, array $opts = array() ): array {
		$args = array(
			'post_type'        => $post_type,
			'post_status'      => $opts['status'] ?? array( 'publish', 'draft' ),
			'numberposts'      => $opts['limit'] ?? -1,
			'orderby'          => $opts['orderby'] ?? 'title',
			'order'            => $opts['order'] ?? 'ASC',
			'suppress_filters' => false,
		);

		if ( ! empty( $opts['author'] ) ) {
			$args['author'] = (int) $opts['author'];
		}
		if ( ! empty( $opts['search'] ) ) {
			$args['s'] = (string) $opts['search'];
		}
		if ( ! empty( $opts['tax_query'] ) ) {
			$args['tax_query'] = $opts['tax_query'];
		}
		if ( ! empty( $opts['meta_query'] ) ) {
			$args['meta_query'] = $opts['meta_query'];
		}

		return get_posts( $args );
	}

	/**
	 * Постраничный запрос постов с общим числом найденных.
	 *
	 * Обёртка над WP_Query для списков с пагинацией (напр. страница «Все задания»):
	 * возвращает и срез постов, и полное число совпадений (found_posts) для infinite scroll.
	 *
	 * @param string $post_type Тип записи.
	 * @param array  $args      Доп. аргументы WP_Query (posts_per_page, offset, tax_query, s, orderby…).
	 *
	 * @return array{posts: \WP_Post[], total: int}
	 */
	public function query( string $post_type, array $args = array() ): array {
		$defaults = array(
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'suppress_filters'    => false,
		);

		$query = new \WP_Query( array_merge( $defaults, $args ) );

		return array(
			'posts' => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Меняет статус публикации поста.
	 *
	 * @param int    $post_id ID поста.
	 * @param string $status  Новый статус (publish | draft | fs_archived | ...).
	 *
	 * @return bool
	 */
	public function updateStatus( int $post_id, string $status ): bool {
		$result = wp_update_post( array(
			'ID'          => $post_id,
			'post_status' => $status,
		) );

		return ! is_wp_error( $result ) && 0 !== $result;
	}

	/**
	 * Находит страницу по slug (path).
	 *
	 * @param string $path      Slug страницы
	 * @param string $post_type Тип поста (по умолчанию 'page')
	 *
	 * @return \WP_Post|null
	 */
	public function findByPath( string $path, string $post_type = 'page' ): ?\WP_Post {
		$page = get_page_by_path( $path, OBJECT, $post_type );
		return $page instanceof \WP_Post ? $page : null;
	}

	/**
	 * Возвращает URL архива типа записи или пустую строку, если архив недоступен.
	 *
	 * @param string $post_type Тип записи.
	 *
	 * @return string
	 */
	public function getArchiveLink( string $post_type ): string {
		$link = get_post_type_archive_link( $post_type );
		return false !== $link ? $link : '';
	}

	/**
	 * Прогоняет сырой контент записи через штатный конвейер `the_content`.
	 *
	 * Нужен там, где контент рендерит не главный цикл (страница статьи собирает
	 * его в билдере): без фильтров не отработают ни wpautop, ни шорткоды, ни oEmbed.
	 *
	 * @param string $content Сырой post_content.
	 *
	 * @return string HTML после фильтров темы и плагинов.
	 */
	public function renderContent( string $content ): string {
		return (string) apply_filters( 'the_content', $content );
	}

	/**
	 * ID записи по её публичному URL; 0 — ссылка ведёт не на запись этого сайта.
	 *
	 * @param string $url Абсолютный или относительный URL.
	 *
	 * @return int
	 */
	public function idFromUrl( string $url ): int {
		return '' === $url ? 0 : url_to_postid( $url );
	}
}
