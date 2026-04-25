<?php

declare( strict_types=1 );

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

/**
 * Class SubjectImportExportCallbacks
 *
 * AJAX-обработчики для импорта и экспорта предметов.
 *
 * @package Inc\Callbacks
 *
 * ### Основные обязанности:
 *
 * 1. **Экспорт предмета** — сбор всех данных предмета (таксономии, термины, посты, метабоксы, boilerplate) в JSON.
 * 2. **Импорт предмета** — восстановление предмета из JSON-файла со всеми связями.
 *
 * ### Архитектурная роль:
 *
 * Делегирует операции с БД репозиториям, а массовые операции — менеджерам (TermManager, PostManager).
 * Использует приватные методы для сбора данных при экспорте и пошагового импорта.
 */
class SubjectImportExportCallbacks extends BaseController {
	use Authorizer;
	use Sanitizer;
	use TaxonomySeeder;

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

	// ============================ AJAX-КОЛЛБЕКИ (Import/Export) ============================ //

	/**
	 * Экспортирует все данные предмета в JSON.
	 *
	 * @return void
	 */
	public function ajaxExportSubject(): void {
		$this->authorize( Nonce::Subject );

		$key     = $this->requireKey( 'key', error: 'ID предмета обязателен' );
		$subject = $this->subjects->getByKey( $key );

		if ( ! $subject ) {
			$this->error( 'Предмет не найден', array( 'key' => $key ) );
		}

		// Формирование массива для экспорта
		$this->success(
			array(
				'subject'      => array(
					'key'  => $subject->key,
					'name' => $subject->name,
				),
				// getRawForSubject() — возвращает записи в виде массивов (не объектов)
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
		$this->authorize( Nonce::Subject );

		// sanitizeHtml() — использует wp_kses_post() для очистки HTML
		$raw = $this->sanitizeHtml( 'json' );
		if ( empty( $raw ) ) {
			$this->error( 'JSON не передан' );
		}

		// json_decode(, true) — преобразует JSON в ассоциативный массив
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['subject']['key'], $data['subject']['name'] ) ) {
			$this->error( 'Неверный формат файла импорта', array( 'raw_length' => strlen( $raw ) ) );
		}

		// sanitize_title() — преобразует строку в slug (транслитерация, нижний регистр, дефисы)
		$key = sanitize_title( (string) $data['subject']['key'] );
		// sanitize_text_field() — удаляет теги и спецсимволы
		$name = sanitize_text_field( (string) $data['subject']['name'] );

		if ( empty( $key ) || empty( $name ) ) {
			$this->error( 'Ключ или название предмета пусты в файле импорта' );
		}

		// Проверка на дубликат
		if ( $this->subjects->getByKey( $key ) ) {
			$this->error( "Предмет с ключом «{$key}» уже существует. Импорт невозможен." );
		}

		// Создание предмета
		$result = $this->subjects->update(
			array(
				'key'  => $key,
				'name' => $name,
			)
		);

		if ( ! $result ) {
			$this->error( 'Критическая ошибка при создании записи предмета в БД' );
		}

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

		// Импорт метабоксов (привязка шаблонов к номерам заданий)
		foreach ( $data['metaboxes'] ?? array() as $task_number => $template_id ) {
			$this->metaboxes->update(
				array(
					'subject'     => $key,
					'task_number' => sanitize_text_field( (string) $task_number ),
					'template_id' => sanitize_text_field( (string) $template_id ),
				)
			);
		}

		// Импорт boilerplate-шаблонов
		foreach ( $data['boilerplates'] ?? array() as $term_slug => $bp_list ) {
			foreach ( (array) $bp_list as $bp ) {
				// uniqid(, true) — генерирует уникальный ID с микросекундами (более уникальный)
				// wp_kses_post() — разрешает только безопасные HTML-теги для контента постов
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

		// Импорт терминов (элементы таксономий)
		foreach ( $data['terms'] ?? array() as $tax_slug => $term_list ) {
			$this->importTerms( sanitize_title( (string) $tax_slug ), (array) $term_list );
		}

		// Импорт постов (задания и статьи)
		$this->importPosts( $data['posts'] ?? array() );

		// flush_rewrite_rules() — перестраивает правила ЧПУ после регистрации новых таксономий/CPT
		flush_rewrite_rules();

		$this->success( array( 'message' => "Предмет «{$name}» успешно импортирован" ) );
	}

	// ============================ ПРИВАТНЫЕ МЕТОДЫ-ХЕЛПЕРЫ ============================ //

	// ============================ Экспорт ============================ //

	/**
	 * Собирает все термины для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array>
	 */
	private function collectTerms( string $subject_key ): array {
		// array_merge() — объединяет массивы
		// array_map() — применяет функцию ко всем элементам (извлекает slug каждого DTO)
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
	 * @return array<string, array>
	 */
	private function collectPosts( string $subject_key ): array {
		$tax_slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		foreach ( array( "{$subject_key}_tasks", "{$subject_key}_articles" ) as $post_type ) {
			$result[ $post_type ] = array_map(
				function ( $post ) use ( $tax_slugs ) {
					$termMap = array();

					// Сбор слаг-терминов для каждой таксономии
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

	// ============================ Импорт ============================ //

	/**
	 * Импортирует термины в указанную таксономию.
	 *
	 * @param string $taxonomy Слаг таксономии
	 * @param array  $terms    Массив данных терминов
	 *
	 * @return void
	 */
	private function importTerms( string $taxonomy, array $terms ): void {
		// ensureTaxonomy() — проверяет существование таксономии и регистрирует её при необходимости
		$this->terms->ensureTaxonomy( $taxonomy );

		foreach ( $terms as $term_data ) {
			$name = sanitize_text_field( $term_data['name'] ?? '' );

			if ( empty( $name ) ) {
				continue;
			}

			// insert() — создаёт новый термин таксономии
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
				// sanitize_key() — очищает строку для использования в качестве ключа (только буквы/цифры/дефисы)
				// wp_kses_post() — разрешает только безопасные HTML-теги
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

				// Импорт мета-полей поста
				foreach ( $post_data['meta'] ?? array() as $meta_key => $meta_value ) {
					$this->posts->updateMeta( $post_id, sanitize_key( (string) $meta_key ), $meta_value );
				}

				// Привязка терминов к посту
				foreach ( $post_data['terms'] ?? array() as $tax_slug => $term_slugs ) {
					// setPostTerms() — устанавливает термины для указанной таксономии
					$this->terms->setPostTerms( $post_id, (array) $term_slugs, sanitize_title( (string) $tax_slug ) );
				}
			}
		}
	}
}
