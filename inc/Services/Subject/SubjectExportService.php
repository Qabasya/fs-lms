<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\Bundle\PostCollector;

/**
 * Class SubjectExportService
 *
 * Сервис для экспорта данных предмета в массив для последующей JSON-сериализации.
 *
 * @package Inc\Services
 *
 * ### Основные обязанности:
 *
 * 1. **Сбор структур предмета** — таксономии, метабоксы, boilerplate, термины.
 * 2. **Сбор банка** — задания и статьи через общий {@see PostCollector}.
 * 3. **Структурирование данных** — приведение данных к формату, совместимому с импортом.
 *
 * ### Архитектурная роль:
 *
 * Делегирует получение данных соответствующим репозиториям и менеджерам.
 * Используется в SubjectImportExportCallbacks для формирования экспортного JSON
 * и в {@see Bundle\SubjectBundleExportService} — как источник структур предмета
 * для полного пакета переноса.
 *
 * ### Разделение с пакетом
 *
 * Здесь остаётся «лёгкий» экспорт: структуры предмета + банк заданий/статей,
 * один JSON без медиа. Полный граф (работы, контрольные, уроки, курсы, задачи
 * глобального банка, физические медиафайлы) — в {@see Bundle\SubjectBundleExportService},
 * который переиспользует {@see structures()} и {@see taxonomySlugs()} отсюда.
 */
class SubjectExportService {

	/**
	 * Конструктор сервиса.
	 *
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов метабоксов
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager           $terms        Менеджер терминов
	 * @param PostCollector         $collector    Сборщик представлений записей
	 */
	public function __construct(
		private readonly TaxonomyRepository    $taxonomies,
		private readonly MetaBoxRepository     $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager           $terms,
		private readonly PostCollector         $collector,
	) {}

	/**
	 * Экспортирует все данные предмета в виде массива.
	 *
	 * @param string $subject_key Ключ предмета (например, 'math')
	 *
	 * @return array Массив с разделами: taxonomies, metaboxes, boilerplates, terms, posts
	 */
	public function export( string $subject_key ): array {
		return array_merge(
			$this->structures( $subject_key ),
			array( 'posts' => $this->collectPosts( $subject_key ) )
		);
	}

	/**
	 * Структуры предмета без записей: таксономии, метабоксы, boilerplate, термины.
	 *
	 * Выделено, чтобы пакет переноса собирал ту же шапку предмета, что и обычный
	 * экспорт, — без копии четырёх приватных методов.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array{taxonomies: array, metaboxes: array, boilerplates: array, terms: array}
	 */
	public function structures( string $subject_key ): array {
		return array(
			'taxonomies'   => $this->exportTaxonomies( $subject_key ),
			'metaboxes'    => $this->exportMetaboxes( $subject_key ),
			'boilerplates' => $this->exportBoilerplates( $subject_key ),
			'terms'        => $this->collectTerms( $subject_key ),
		);
	}

	/**
	 * Слаги всех таксономий предмета: системная «номера заданий» + пользовательские.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return string[]
	 */
	public function taxonomySlugs( string $subject_key ): array {
		return array_merge(
			array( PostTypeResolver::getTaskTaxonomy( $subject_key ) ),
			array_map( fn( $dto ) => $dto->slug, $this->taxonomies->getBySubject( $subject_key ) )
		);
	}

	/**
	 * Экспортирует пользовательские таксономии предмета.
	 *
	 * @param string $subject_key Ключ предмета
	 *
	 * @return array [tax_slug => ['name', 'display_type', 'is_required', 'use_in_articles']]
	 */
	private function exportTaxonomies( string $subject_key ): array {
		$result = array();
		foreach ( $this->taxonomies->getBySubject( $subject_key ) as $dto ) {
			$result[ $dto->slug ] = array(
				'name'            => $dto->name,
				'display_type'    => $dto->display_type,
				'is_required'     => $dto->is_required,
				'use_in_articles' => $dto->use_in_articles,
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
		$result = array();

		foreach ( $this->taxonomySlugs( $subject_key ) as $tax_slug ) {
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
		$tax_slugs = $this->taxonomySlugs( $subject_key );
		$result    = array();

		// PostTypeResolver::tasks() — возвращает тип поста заданий (например, 'math_tasks')
		// PostTypeResolver::articles() — возвращает тип поста статей (например, 'math_articles')
		foreach ( array( PostTypeResolver::tasks( $subject_key ), PostTypeResolver::articles( $subject_key ) ) as $post_type ) {
			$result[ $post_type ] = $this->collector->collect( $post_type, $tax_slugs );
		}

		return $result;
	}
}
