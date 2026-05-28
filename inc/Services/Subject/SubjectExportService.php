<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Managers\PostManager;
use Inc\Managers\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\PostTypeResolver;

/**
 * Class SubjectExportService
 *
 * Сервис для экспорта данных предмета в массив для последующей JSON-сериализации.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Сбор данных предмета** — экспорт таксономий, метабоксов, boilerplate, терминов и постов.
 * 2. **Структурирование данных** — приведение данных к формату, совместимому с импортом.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение данных соответствующим репозиториям и менеджерам.
 * Используется в SubjectImportExportCallbacks для формирования экспортного JSON.
 * Экспортирует все сущности, связанные с предметом, включая кастомные типы постов.
 */
class SubjectExportService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов метабоксов
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager           $terms        Менеджер терминов
	 * @param PostManager           $posts        Менеджер постов
	 */
	public function __construct(
		private readonly TaxonomyRepository   $taxonomies,
		private readonly MetaBoxRepository    $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager          $terms,
		private readonly PostManager          $posts,
	) {}

	/**
	 * Экспортирует все данные предмета в виде массива.
	 *
	 * @param string $subject_key Ключ предмета (например, 'math')
	 *
	 * @return array Массив с разделами: taxonomies, metaboxes, boilerplates, terms, posts
	 */
	public function export( string $subject_key ): array {
		return array(
			'taxonomies'   => $this->exportTaxonomies( $subject_key ),
			'metaboxes'    => $this->exportMetaboxes( $subject_key ),
			'boilerplates' => $this->exportBoilerplates( $subject_key ),
			'terms'        => $this->collectTerms( $subject_key ),
			'posts'        => $this->collectPosts( $subject_key ),
		);
	}

	/**
	 * Экспортирует пользовательские таксономии предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array [tax_slug => ['name', 'display_type', 'is_required']]
	 */
	private function exportTaxonomies( string $subject_key ): array {
		$result = array();
		foreach ( $this->taxonomies->getBySubject( $subject_key ) as $dto ) {
			$result[ $dto->slug ] = array(
				'name'         => $dto->name,
				'display_type' => $dto->display_type,
				'is_required'  => $dto->is_required,
			);
		}
		return $result;
	}

	/**
	 * Экспортирует привязки шаблонов метабоксов (номер задания → шаблон).
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array [task_number => template_id]
	 */
	private function exportMetaboxes( string $subject_key ): array {
		$result = array();
		// readAll() — возвращает все привязки в виде массива DTO
		foreach ( $this->metaboxes->readAll() as $dto ) {
			if ( $dto->subject_key === $subject_key ) {
				$result[ $dto->task_number ] = $dto->template_id;
			}
		}
		return $result;
	}

	/**
	 * Экспортирует типовые условия (boilerplate) заданий.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array [term_slug => [['uid', 'title', 'content', 'is_default'], ...]]
	 */
	private function exportBoilerplates( string $subject_key ): array {
		$result = array();
		// readAll() — возвращает все boilerplate в виде плоского массива DTO
		foreach ( $this->boilerplates->readAll() as $dto ) {
			if ( $dto->subject_key === $subject_key ) {
				$result[ $dto->term_slug ][] = array(
					'uid'        => $dto->uid,
					'title'      => $dto->title,
					'content'    => $dto->content,
					'is_default' => $dto->is_default,
				);
			}
		}
		return $result;
	}

	/**
	 * Собирает все термины (элементы таксономий) для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array> [tax_slug => [['name', 'slug', 'description', 'parent'], ...]]
	 */
	private function collectTerms( string $subject_key ): array {
		// Сбор всех таксономий предмета (системная + пользовательские)
		$slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		foreach ( $slugs as $tax_slug ) {
			$result[ $tax_slug ] = array_map(
				fn( $t ) => array(
					'name'        => $t->name,
					'slug'        => $t->slug,
					'description' => $t->description,
					'parent'      => $t->parent,
				),
				// getAll() — возвращает все термины указанной таксономии
				$this->terms->getAll( $tax_slug )
			);
		}

		return $result;
	}

	/**
	 * Собирает все посты (задания и статьи) для указанного предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array<string, array> [post_type => [['post_title', 'post_content', ...], ...]]
	 */
	private function collectPosts( string $subject_key ): array {
		// Список таксономий для получения привязок терминов
		$tax_slugs = array_merge(
			array( "{$subject_key}_task_number" ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);

		$result = array();

		// PostTypeResolver::tasks() — возвращает тип поста заданий (например, 'math_tasks')
		// PostTypeResolver::articles() — возвращает тип поста статей (например, 'math_articles')
		foreach ( array( PostTypeResolver::tasks( $subject_key ), PostTypeResolver::articles( $subject_key ) ) as $post_type ) {
			$result[ $post_type ] = array_map(
				function ( $post ) use ( $tax_slugs ) {
					$term_map = array();

					// Сбор слагов терминов для каждой таксономии
					foreach ( $tax_slugs as $tax_slug ) {
						$slugs = $this->terms->getPostSlugs( $post->ID, $tax_slug );
						if ( ! empty( $slugs ) ) {
							$term_map[ $tax_slug ] = $slugs;
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
						'terms'        => $term_map,
					);
				},
				// getAll() — возвращает все посты указанного типа
				$this->posts->getAll( $post_type )
			);
		}

		return $result;
	}
}