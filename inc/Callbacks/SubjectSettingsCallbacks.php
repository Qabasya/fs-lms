<?php

declare(strict_types=1);

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\DTO\TaskTypeBoilerplateDTO;
use Inc\Enums\Nonce;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Repositories\MetaBoxRepository;
use Inc\Repositories\SubjectRepository;
use Inc\Repositories\TaxonomyRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;
use Inc\Shared\Traits\TaxonomySeeder;
use Inc\Shared\Traits\TemplateRenderer;

/**
 * Class SubjectSettingsCallbacks
 *
 * AJAX-обработчики для CRUD-операций с предметами,
 * а также экспорт/импорт полных данных предмета.
 *
 * @package Inc\Callbacks
 */
class SubjectSettingsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;
	use TemplateRenderer;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository     $subjects     Репозиторий предметов
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository     $metaboxes    Репозиторий метабоксов (привязка шаблонов)
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager           $terms        Менеджер терминов
	 * @param PostManager           $posts        Менеджер постов
	 */
	public function __construct(
		private SubjectRepository $subjects,
		private TaxonomyRepository $taxonomies,
		private MetaBoxRepository $metaboxes,
		private BoilerplateRepository $boilerplates,
		private TermManager $terms,
		private PostManager $posts,
	) {
		parent::__construct();
	}

	// ============================ AJAX-КОЛЛБЕКИ ============================ //

	/**
	 * Создаёт новый предмет и засевает таксономию номеров заданий.
	 *
	 * @return void
	 */
	public function ajaxStoreSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		$key  = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name = $this->requireText( 'name', error: 'Название предмета обязательно' );

		// Получение количества заданий (по умолчанию 0)
		$count = $this->sanitizeInt( 'tasks_count' );

		// Сохранение предмета через репозиторий
		$success = $this->subjects->update(
			array(
				'key'  => $key,
				'name' => $name,
			)
		);

		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при создании предмета' );
			return;
		}

		// Засев таксономии номерами заданий
		$this->seedTaskNumbers( "{$key}_task_number", $count, $key );

		// Сброс правил перезаписи для активации новых CPT
		flush_rewrite_rules();

		wp_send_json_success( "Предмет «{$name}» успешно создан!" );
	}

	/**
	 * Обновляет название существующего предмета.
	 *
	 * @return void
	 */
	public function ajaxUpdateSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		$key  = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$name = $this->requireText( 'name', error: 'Название предмета обязательно' );

		// Проверка существования предмета
		$this->requireExists( $key );

		// Обновление предмета через репозиторий
		$success = $this->subjects->update(
			array(
				'key'  => $key,
				'name' => $name,
			)
		);

		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при обновлении предмета' );
			return;
		}

		wp_send_json_success( "Предмет «{$name}» обновлён" );
	}

	/**
	 * Удаляет предмет из базы данных каскадно (все связанные данные).
	 *
	 * @return void
	 */
	public function ajaxDeleteSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		$key = $this->requireKey( 'key', error: 'ID предмета обязателен' );

		// Проверка существования предмета
		$this->requireExists( $key );

		// Каскадное удаление всех связанных данных
		$this->cascadeDelete( $key );

		// Удаление предмета через репозиторий
		$success = $this->subjects->delete( array( 'key' => $key ) );

		if ( ! $success ) {
			wp_send_json_error( 'Ошибка при удалении предмета' );
			return;
		}

		// Сброс правил перезаписи после удаления CPT
		flush_rewrite_rules();

		wp_send_json_success( 'Предмет удалён' );
	}

	/**
	 * Экспортирует все данные предмета в JSON.
	 *
	 * @return void
	 */
	public function ajaxExportSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		$key     = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$subject = $this->subjects->getByKey( $key );

		if ( ! $subject ) {
			wp_send_json_error( 'Предмет не найден' );
			return;
		}

		wp_send_json_success(
			array(
				'subject'      => array(
					'key'  => $subject->key,
					'name' => $subject->name,
				),
				'taxonomies'   => $this->taxonomies->getRawForSubject( $key ),
				'metaboxes'    => $this->metaboxes->getRawForSubject( $key ),
				'boilerplates' => $this->boilerplates->getRawForSubject( $key ),
				'terms'        => $this->collectTerms( $key ),
				'posts'        => $this->collectPosts( $key ),
			)
		);
	}

	/**
	 * Импортирует полные данные предмета из JSON.
	 *
	 * @return void
	 */
	public function ajaxImportSubject(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и декодирование JSON
		$raw = $this->sanitizeHtml( 'json' );

		if ( empty( $raw ) ) {
			wp_send_json_error( 'JSON не передан' );
			return;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['subject']['key'], $data['subject']['name'] ) ) {
			wp_send_json_error( 'Неверный формат файла' );
			return;
		}

		$key  = sanitize_title( $data['subject']['key'] );
		$name = sanitize_text_field( $data['subject']['name'] );

		if ( empty( $key ) || empty( $name ) ) {
			wp_send_json_error( 'Ключ или название предмета пусты' );
			return;
		}

		// Проверка на существование предмета
		if ( $this->subjects->getByKey( $key ) ) {
			wp_send_json_error( "Предмет с ключом «{$key}» уже существует" );
			return;
		}

		// Создание предмета
		$this->subjects->update(
			array(
				'key'  => $key,
				'name' => $name,
			)
		);

		// Импорт таксономий
		foreach ( $data['taxonomies'] ?? array() as $tax_slug => $tax_data ) {
			$this->taxonomies->update(
				array(
					'subject_key'  => $key,
					'tax_slug'     => sanitize_title( (string) $tax_slug ),
					'name'         => sanitize_text_field( $tax_data['name'] ?? '' ),
					'display_type' => sanitize_text_field( $tax_data['display_type'] ?? 'select' ),
				)
			);
		}

		// Импорт привязок метабоксов (шаблонов)
		foreach ( $data['metaboxes'] ?? array() as $task_number => $template_id ) {
			$this->metaboxes->update(
				array(
					'subject'     => $key,
					'task_number' => sanitize_text_field( (string) $task_number ),
					'template_id' => sanitize_text_field( (string) $template_id ),
				)
			);
		}

		// Импорт типовых условий (boilerplate)
		foreach ( $data['boilerplates'] ?? array() as $term_slug => $bp_list ) {
			foreach ( (array) $bp_list as $bp ) {
				$this->boilerplates->updateBoilerplate(
					new TaskTypeBoilerplateDTO(
						uid: sanitize_text_field( $bp['uid'] ?? uniqid( 'bp_', true ) ),
						subject_key: $key,
						term_slug: sanitize_text_field( (string) $term_slug ),
						title: sanitize_text_field( $bp['title'] ?? '' ),
						content: wp_kses_post( $bp['content'] ?? '' ),
						is_default: (bool) ( $bp['is_default'] ?? false ),
					)
				);
			}
		}

		// Импорт терминов
		foreach ( $data['terms'] ?? array() as $tax_slug => $term_list ) {
			$this->importTerms( sanitize_title( (string) $tax_slug ), (array) $term_list );
		}

		// Импорт постов (заданий и статей)
		$this->importPosts( $data['posts'] ?? array() );

		// Сброс правил перезаписи для активации новых CPT
		flush_rewrite_rules();

		wp_send_json_success( "Предмет «{$name}» успешно импортирован" );
	}


	// === === === Прочие AJAX хуки, связанные с предметами === === ===  //

	/**
	 * Возвращает HTML таблицы постов для AJAX-обновления вкладки.
	 *
	 * @return void
	 */
	public function ajaxGetPostsTable(): void {
		// Проверка прав доступа и nonce
		$this->authorize( Nonce::Subject );

		// Получение и валидация параметров
		$subject_key = $this->sanitizeKey( 'subject_key' );
		$tab         = $this->sanitizeKey( 'tab' );
		$page_slug   = $this->sanitizeText( 'page_slug' );
		$post_status = $this->sanitizeKey( 'post_status' );
		$paged       = max( 1, $this->sanitizeInt( 'paged' ) );
		$s           = $this->sanitizeText( 's' );

		// Валидация параметров
		if ( ! in_array( $tab, array( 'tab-2', 'tab-3' ), true ) || ! $subject_key ) {
			wp_send_json_error( 'Invalid parameters.' );
		}

		// Установка глобальных переменных для WP_List_Table
		if ( $post_status ) {
			$_GET['post_status'] = $_REQUEST['post_status'] = $post_status;
		} else {
			unset( $_GET['post_status'], $_REQUEST['post_status'] );
		}

		if ( $paged > 1 ) {
			$_GET['paged'] = $_REQUEST['paged'] = $paged;
		} else {
			unset( $_GET['paged'], $_REQUEST['paged'] );
		}

		if ( $s !== '' ) {
			$_GET['s'] = $_REQUEST['s'] = $s;
		} else {
			unset( $_GET['s'], $_REQUEST['s'] );
		}

		// Определение типа поста в зависимости от вкладки
		$post_type = $tab === 'tab-2'
			? "{$subject_key}_tasks"
			: "{$subject_key}_articles";

		// Построение таблицы через PostManager
		$t = $this->posts->buildListTable( $post_type, $page_slug, $tab );

		// Получение HTML представлений
		$views_html = $t->views();

		// Получение HTML поиска
		ob_start();
		$t->table->search_box( $t->post_type_object->labels->search_items, 'post' );
		$search_html = (string) ob_get_clean();

		// Получение HTML таблицы и инлайн-редактора
		$table_html  = $t->display();
		$inline_html = $t->inlineEdit();

		// Восстановление глобальных переменных
		$t->restore();

		// Сборка финального HTML
		$html = $views_html
				. '<form id="posts-filter" method="get">'
				. $search_html
				. $table_html
				. '</form>'
				. '<div id="ajax-response"></div>'
				. $inline_html;

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Получает список задач и статей по конкретному номеру задания (термину).
	 *
	 * @return void
	 */
	public function ajaxGetTasksByNumber(): void {
		$this->authorize( Nonce::Subject );

		$subject_key     = $this->requireKey( 'subject_key' );
		$term_id         = absint( $_POST['term_id'] ?? 0 );
		$task_cpt        = "{$subject_key}_tasks";
		$number_taxonomy = "{$subject_key}_task_number";

		if ( ! $term_id ) {
			wp_send_json_error( 'Не выбран номер задания' );
			return;
		}

		$taxonomies  = $this->taxonomies->getBySubject( $subject_key );
		$visible_tax = array_filter( $taxonomies, fn( $t ) => $t->slug !== $number_taxonomy );

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$wp_query = new \WP_Query(
			array(
				'post_type'      => $task_cpt,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => $number_taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		// Строим данные строк здесь — get_edit_post_link() и get_the_terms()
		// вызываются по одному разу на пост, без модификации глобального $post.
		$rows = array_map(
			function ( \WP_Post $post ) use ( $visible_tax ): array {
				$terms = array();
				foreach ( $visible_tax as $tax ) {
					$wp_terms            = get_the_terms( $post->ID, $tax->slug );
					$terms[ $tax->slug ] = ( ! empty( $wp_terms ) && ! is_wp_error( $wp_terms ) )
						? implode( ', ', wp_list_pluck( $wp_terms, 'name' ) )
						: '';
				}

				return array(
					'title'     => $post->post_title,
					'number'    => $post->post_name,
					'status'    => $post->post_status,
					'edit_link' => get_edit_post_link( $post->ID ) ?? '',
					'terms'     => $terms,
				);
			},
			$wp_query->posts
		);

		ob_start();
		$this->render(
			'components/ajax-tables/task-ajax-table',
			array(
				'rows'       => $rows,
				'taxonomies' => $visible_tax,
			)
		);
		$html = (string) ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}
	
	/**
	 * Получает последние 10 созданных задач по предмету.
	 */
	public function ajaxGetRecentTasks(): void {
		$this->authorize( Nonce::Subject );
		
		$subject_key = $this->requireKey( 'subject_key' );
		$task_cpt    = "{$subject_key}_tasks";
		$tax_number  = "{$subject_key}_task_number";
		
		// Получаем список таксономий предмета
		$taxonomies = $this->taxonomies->getBySubject( $subject_key );
		
		$query = new \WP_Query( array(
			'post_type'      => $task_cpt,
			'posts_per_page' => 10,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		
		$rows = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				
				// Собираем термины "Номера задания"
				$numbers = get_the_terms( $post_id, $tax_number );
				$number_val = $numbers ? implode( ', ', wp_list_pluck( $numbers, 'name' ) ) : '—';
				
				$terms_data = array();
				foreach ( $taxonomies as $tax ) {
					if ( $tax->slug === $tax_number ) continue;
					$terms = get_the_terms( $post_id, $tax->slug );
					$terms_data[ $tax->slug ] = $terms ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
				}
				
				$rows[] = array(
					'number'    => $number_val,
					'title'     => get_the_title(),
					'edit_link' => get_edit_post_link( $post_id ),
					'terms'     => $terms_data,
				);
			}
			wp_reset_postdata();
		}
		
		ob_start();
		$this->render( 'components/ajax-tables/tasks-ajax-table', array(
			'rows'       => $rows,
			'taxonomies' => array_filter( $taxonomies, fn($t) => $t->slug !== $tax_number ),
		) );
		
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ ============================ //

	/**
	 * Проверяет существование предмета в БД.
	 * Завершает выполнение, если предмет не найден.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function requireExists( string $key ): void {
		if ( ! $this->subjects->getByKey( $key ) ) {
			wp_send_json_error( 'Предмет не найден в базе данных' );
		}
	}

	/**
	 * Каскадное удаление всех данных, связанных с предметом.
	 *
	 * @param string $key Ключ предмета
	 *
	 * @return void
	 */
	private function cascadeDelete( string $key ): void {
		// Удаление терминов пользовательских таксономий
		foreach ( $this->taxonomies->getBySubject( $key ) as $tax_dto ) {
			$this->terms->deleteAll( $tax_dto->slug );
		}

		// Удаление терминов системной таксономии номеров заданий
		$this->terms->deleteAll( "{$key}_task_number" );

		// Удаление всех постов заданий и статей
		foreach ( array( "{$key}_tasks", "{$key}_articles" ) as $post_type ) {
			$this->posts->deleteAll( $post_type );
		}

		// Удаление записей из репозиториев
		$this->taxonomies->deleteBySubject( $key );
		$this->metaboxes->deleteBySubject( $key );
		$this->boilerplates->deleteBySubject( $key );
	}

	/**
	 * Собирает все термины для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array> Массив терминов, сгруппированных по таксономиям
	 */
	private function collectTerms( string $subject_key ): array {
		// Список всех таксономий предмета (системная + пользовательские)
		$slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		foreach ( $slugs as $tax_slug ) {
			$wpTerms = $this->terms->getAll( $tax_slug );

			$result[ $tax_slug ] = array_map(
				fn( $t ) => array(
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'parent'      => $t->parent,
				),
				$wpTerms
			);
		}

		return $result;
	}

	/**
	 * Собирает все посты (задания и статьи) для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array> Массив постов, сгруппированных по типам
	 */
	private function collectPosts( string $subject_key ): array {
		// Список всех таксономий для получения привязок терминов
		$tax_slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		foreach ( array( "{$subject_key}_tasks", "{$subject_key}_articles" ) as $post_type ) {
			$result[ $post_type ] = array_map(
				function ( $post ) use ( $tax_slugs ) {
					$termMap = array();

					foreach ( $tax_slugs as $tax_slug ) {
						$slugs = $this->terms->getPostSlugs( $post->ID, $tax_slug );
						if ( ! empty( $slugs ) ) {
							$termMap[ $tax_slug ] = $slugs;
						}
					}

					return array(
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_status'  => $post->post_status,
						'post_date'    => $post->post_date,
						'menu_order'   => (int) $post->menu_order,
						'meta'         => $this->posts->getAllMeta( $post->ID ),
						'terms'        => $termMap,
					);
				},
				$this->posts->getAll( $post_type )
			);
		}

		return $result;
	}

	/**
	 * Импортирует термины в указанную таксономию.
	 *
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $terms    Массив данных терминов
	 *
	 * @return void
	 */
	private function importTerms( string $taxonomy, array $terms ): void {
		// Убеждаемся, что таксономия существует
		$this->terms->ensureTaxonomy( $taxonomy );

		foreach ( $terms as $term_data ) {
			$name = sanitize_text_field( $term_data['name'] ?? '' );

			if ( empty( $name ) ) {
				continue;
			}

			$this->terms->insert(
				$name,
				$taxonomy,
				array(
					'slug'        => sanitize_title( $term_data['slug'] ?? $name ),
					'description' => sanitize_text_field( $term_data['description'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Импортирует посты (задания и статьи) из массива данных.
	 *
	 * @param array $posts_data Массив постов, сгруппированных по типам
	 *
	 * @return void
	 */
	private function importPosts( array $posts_data ): void {
		foreach ( $posts_data as $post_type => $post_list ) {
			foreach ( (array) $post_list as $post_data ) {
				// Создание поста
				$post_id = $this->posts->insert(
					array(
						'post_type'    => sanitize_key( (string) $post_type ),
						'post_title'   => sanitize_text_field( $post_data['post_title'] ?? '' ),
						'post_content' => wp_kses_post( $post_data['post_content'] ?? '' ),
						'post_excerpt' => sanitize_text_field( $post_data['post_excerpt'] ?? '' ),
						'post_status'  => sanitize_text_field( $post_data['post_status'] ?? 'publish' ),
						'post_date'    => sanitize_text_field( $post_data['post_date'] ?? '' ),
						'menu_order'   => absint( $post_data['menu_order'] ?? 0 ),
					)
				);

				if ( ! $post_id ) {
					continue;
				}

				// Импорт мета-полей
				foreach ( $post_data['meta'] ?? array() as $meta_key => $meta_value ) {
					$this->posts->updateMeta( $post_id, sanitize_key( (string) $meta_key ), $meta_value );
				}

				// Привязка терминов
				foreach ( $post_data['terms'] ?? array() as $tax_slug => $term_slugs ) {
					$this->terms->setPostTerms( $post_id, (array) $term_slugs, sanitize_title( (string) $tax_slug ) );
				}
			}
		}
	}
}
