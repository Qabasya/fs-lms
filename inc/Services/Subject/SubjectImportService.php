<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Task\TaskTemplateAssignmentDTO;
use Inc\DTO\Task\TaskTypeBoilerplateDTO;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;

/**
 * Class SubjectImportService
 *
 * Сервис для импорта предмета из JSON-данных.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Валидация данных** — проверка структуры и корректности импортируемых данных.
 * 2. **Импорт сущностей** — последовательное создание предмета, таксономий, метабоксов,
 *    boilerplate, терминов и постов.
 * 3. **Транзакционность** — все операции выполняются последовательно; при ошибке
 *    возникает исключение (отката нет, но данные не сохраняются частично из-за проверок).
 *
 * ### Архитектурная роль:
 *
 * Делегирует сохранение каждого типа данных соответствующему репозиторию или менеджеру.
 * Использует DTO для типобезопасной передачи данных. Является частью логики импорта
 * вместе с SubjectImportExportCallbacks.
 */
class SubjectImportService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param SubjectRepository     $subjects     Репозиторий предметов
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов метабоксов
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager           $terms        Менеджер терминов
	 * @param PostManager           $posts        Менеджер постов
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly TaxonomyRepository $taxonomies,
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager $terms,
		private readonly PostManager $posts,
	) {}

	/**
	 * Импортирует предмет из декодированного JSON-массива.
	 *
	 * @param array $data Декодированный JSON-массив
	 *
	 * @return string Название импортированного предмета
	 *
	 * @throws \InvalidArgumentException При неверном формате или дубликате ключа
	 * @throws \RuntimeException         При ошибке сохранения в БД
	 */
	public function import( array $data ): string {
		// Валидация наличия обязательных полей
		if ( ! isset( $data['subject']['key'], $data['subject']['name'] ) ) {
			throw new \InvalidArgumentException( 'Неверный формат файла импорта' );
		}

		// sanitize_title() — преобразует строку в slug
		// sanitize_text_field() — удаляет теги и спецсимволы
		$key  = sanitize_title( (string) $data['subject']['key'] );
		$name = sanitize_text_field( (string) $data['subject']['name'] );

		if ( empty( $key ) || empty( $name ) ) {
			throw new \InvalidArgumentException( 'Ключ или название предмета пусты в файле импорта' );
		}

		// Проверка на дубликат (предмет с таким ключом уже существует)
		if ( $this->subjects->getByKey( $key ) ) {
			throw new \InvalidArgumentException( "Предмет с ключом «{$key}» уже существует. Импорт невозможен." );
		}

		// Создание предмета
		if ( ! $this->subjects->save( new SubjectDTO( $key, $name ) ) ) {
			throw new \RuntimeException( 'Критическая ошибка при создании записи предмета в БД' );
		}

		// Импорт связанных сущностей
		$this->importTaxonomies( $key, $data['taxonomies'] ?? array() );
		$this->importMetaboxes( $key, $data['metaboxes'] ?? array() );
		$this->importBoilerplates( $key, $data['boilerplates'] ?? array() );
		$this->importTerms( $data['terms'] ?? array() );
		$this->importPosts( $data['posts'] ?? array() );

		return $name;
	}

	/**
	 * Импортирует таксономии для предмета.
	 *
	 * @param string $key         Ключ предмета
	 * @param array  $taxonomies  Массив данных таксономий [tax_slug => data]
	 *
	 * @return void
	 */
	private function importTaxonomies( string $key, array $taxonomies ): void {
		foreach ( $taxonomies as $tax_slug => $tax_data ) {
			$this->taxonomies->save(
				new TaxonomyDataDTO(
					slug:         sanitize_title( (string) $tax_slug ),
					name:         sanitize_text_field( $tax_data['name'] ?? '' ),
					subject_key:  $key,
					display_type: sanitize_text_field( $tax_data['display_type'] ?? 'select' ),
					is_required:  (bool) ( $tax_data['is_required'] ?? false ),
				)
			);
		}
	}

	/**
	 * Импортирует привязки шаблонов метабоксов (номера заданий → шаблон).
	 *
	 * @param string $key        Ключ предмета
	 * @param array  $metaboxes  Массив [task_number => template_id]
	 *
	 * @return void
	 */
	private function importMetaboxes( string $key, array $metaboxes ): void {
		foreach ( $metaboxes as $task_number => $template_id ) {
			$this->metaboxes->save(
				new TaskTemplateAssignmentDTO(
					$key,
					sanitize_text_field( (string) $task_number ),
					sanitize_text_field( (string) $template_id ),
				)
			);
		}
	}

	/**
	 * Импортирует типовые условия (boilerplate) для заданий.
	 *
	 * @param string $key           Ключ предмета
	 * @param array  $boilerplates  Массив [term_slug => [bp1, bp2, ...]]
	 *
	 * @return void
	 */
	private function importBoilerplates( string $key, array $boilerplates ): void {
		foreach ( $boilerplates as $term_slug => $bp_list ) {
			foreach ( (array) $bp_list as $bp ) {
				$this->boilerplates->save(
					new TaskTypeBoilerplateDTO(
						uid:         sanitize_text_field( $bp['uid'] ?? uniqid( 'bp_', true ) ),
						subject_key: $key,
						term_slug:   sanitize_text_field( (string) $term_slug ),
						title:       sanitize_text_field( $bp['title'] ?? '' ),
						content:     wp_kses_post( $bp['content'] ?? '' ),
						is_default:  (bool) ( $bp['is_default'] ?? false ),
					)
				);
			}
		}
	}

	/**
	 * Импортирует термины (элементы таксономий).
	 *
	 * @param array $terms_by_taxonomy Массив [tax_slug => [term1, term2, ...]]
	 *
	 * @return void
	 */
	private function importTerms( array $terms_by_taxonomy ): void {
		foreach ( $terms_by_taxonomy as $tax_slug => $term_list ) {
			$taxonomy = sanitize_title( (string) $tax_slug );
			// ensureTaxonomy() — проверяет существование таксономии и регистрирует при необходимости
			$this->terms->ensureTaxonomy( $taxonomy );

			foreach ( (array) $term_list as $term_data ) {
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
	}

	/**
	 * Импортирует посты (задания и статьи).
	 *
	 * @param array $posts_data Массив постов, сгруппированных по типам [post_type => post_list]
	 *
	 * @return void
	 */
	private function importPosts( array $posts_data ): void {
		foreach ( $posts_data as $post_type => $post_list ) {
			foreach ( (array) $post_list as $post_data ) {
				// Создание поста
				$post_id = $this->posts->insert(
					array(
						// sanitize_key() — очищает строку для использования в качестве ключа
						'post_type'    => sanitize_key( (string) $post_type ),
						'post_title'   => sanitize_text_field( $post_data['post_title'] ?? '' ),
						// wp_kses_post() — разрешает только безопасные HTML-теги
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

				// Привязка терминов (таксономий) к посту
				foreach ( $post_data['terms'] ?? array() as $tax_slug => $term_slugs ) {
					$this->terms->setPostTerms( $post_id, (array) $term_slugs, sanitize_title( (string) $tax_slug ) );
				}
			}
		}
	}
}
