<?php

declare( strict_types=1 );

namespace Inc\Services\Subject;

use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Subject\SubjectImportReportDTO;
use Inc\DTO\Task\TaskTemplateAssignmentDTO;
use Inc\DTO\Task\TaskTypeBoilerplateDTO;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\Import\ImportRollbackService;

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
 * 2. **Предпросмотр (dry-run)** — подсчёт объектов и поиск коллизий без записи в БД.
 * 3. **Импорт сущностей** — последовательное создание предмета, таксономий, метабоксов,
 *    boilerplate, терминов и постов.
 * 4. **Откат** — при ошибке на любом шаге удаляются все созданные этим запуском сущности.
 *
 * ### Атомарность (A5)
 *
 * Полноценной транзакции здесь быть не может: `wp_insert_post()`,
 * `wp_insert_term()` и запись в `wp_options` не откатываются ROLLBACK'ом
 * (то же ограничение уже осознанно принято в `EnrolledStudentRowImporter`).
 * Поэтому применяется компенсирующий откат: всё созданное пишется в
 * {@see ImportedEntitiesCollector}, а при исключении {@see ImportRollbackService}
 * удаляет ровно это. Наружу исключение перебрасывается — но уже с чистой БД,
 * без «полупредмета».
 *
 * ### Предпросмотр (A7)
 *
 * `preview()` выполняет ту же валидацию, что и `import()`, но вместо записи
 * считает объекты и собирает конфликты. Для графа импорта это единственный
 * способ не узнать об ошибке после получаса записи.
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
	 * @param ImportRollbackService $rollback     Компенсирующее удаление при ошибке
	 * @param SubjectPagesService   $pages        Страницы публичного лендинга предмета
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly TaxonomyRepository $taxonomies,
		private readonly MetaBoxRepository $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager $terms,
		private readonly PostManager $posts,
		private readonly ImportRollbackService $rollback,
		private readonly SubjectPagesService $pages,
	) {}

	/**
	 * Считает, что даст импорт, ничего не записывая (A7).
	 *
	 * @param array $data Декодированный JSON-массив
	 *
	 * @return SubjectImportReportDTO Отчёт с counts/collisions/warnings
	 *
	 * @throws \InvalidArgumentException При неверном формате файла
	 */
	public function preview( array $data ): SubjectImportReportDTO {
		[ $key, $name ] = $this->readSubjectHeader( $data );

		$collisions = array();
		$warnings   = array();

		if ( $this->subjects->getByKey( $key ) ) {
			$collisions[] = $this->duplicateKeyMessage( $key );
		}

		// Термины импортируются «если ещё нет» — существующие слаги не ломают
		// импорт, но пользователь должен знать, что они будут переиспользованы.
		foreach ( $data['terms'] ?? array() as $tax_slug => $term_list ) {
			$taxonomy = sanitize_title( (string) $tax_slug );
			foreach ( (array) $term_list as $term_data ) {
				$term_name = sanitize_text_field( $term_data['name'] ?? '' );
				if ( '' !== $term_name && taxonomy_exists( $taxonomy ) && $this->terms->exists( $term_name, $taxonomy ) ) {
					$warnings[] = "Термин «{$term_name}» уже существует в «{$taxonomy}» — будет переиспользован.";
				}
			}
		}

		return new SubjectImportReportDTO(
			dryRun:      true,
			subjectKey:  $key,
			subjectName: $name,
			counts:      $this->countSections( $data ),
			collisions:  $collisions,
			warnings:    $warnings,
		);
	}

	/**
	 * Импортирует предмет из декодированного JSON-массива.
	 *
	 * @param array $data Декодированный JSON-массив
	 *
	 * @return SubjectImportReportDTO Отчёт о созданном
	 *
	 * @throws \InvalidArgumentException При неверном формате или дубликате ключа
	 * @throws \RuntimeException         При ошибке сохранения в БД
	 */
	public function import( array $data ): SubjectImportReportDTO {
		[ $key, $name ] = $this->readSubjectHeader( $data );

		// Проверка на дубликат (предмет с таким ключом уже существует)
		if ( $this->subjects->getByKey( $key ) ) {
			throw new \InvalidArgumentException( $this->duplicateKeyMessage( $key ) );
		}

		$created = new ImportedEntitiesCollector();

		try {
			// Создание предмета; hasBank переносим из экспорта (по умолчанию true — старые
			// файлы экспорта, созданные до Эпика 18, этого поля не содержат).
			$hasBank = (bool) ( $data['subject']['hasBank'] ?? true );
			$subject = new SubjectDTO( $key, $name, hasBank: $hasBank );
			if ( ! $this->subjects->save( $subject ) ) {
				throw new \RuntimeException( 'Критическая ошибка при создании записи предмета в БД' );
			}
			$created->addSubject( $key );

			// Импорт связанных сущностей
			$this->importTaxonomies( $key, $data['taxonomies'] ?? array() );
			$this->importMetaboxes( $key, $data['metaboxes'] ?? array() );
			$this->importBoilerplates( $key, $data['boilerplates'] ?? array() );
			$this->importTerms( $data['terms'] ?? array(), $created );
			$this->importPosts( $data['posts'] ?? array(), $created );
		} catch ( \Throwable $e ) {
			// Компенсирующий откат: убираем «полупредмет» и отдаём ошибку наружу.
			$this->rollback->undo( $created );
			throw $e;
		}

		// Лендинг заводим последним: откат импорта страниц не удаляет,
		// и после неудачной попытки их остаться не должно.
		$this->pages->ensureForSubject( $subject );

		return new SubjectImportReportDTO(
			dryRun:      false,
			subjectKey:  $key,
			subjectName: $name,
			counts:      $this->countSections( $data ),
		);
	}

	/**
	 * Читает и валидирует шапку файла импорта.
	 *
	 * @param array $data Декодированный JSON-массив
	 *
	 * @return array{0: string, 1: string} [ключ, название]
	 *
	 * @throws \InvalidArgumentException При неверном формате
	 */
	private function readSubjectHeader( array $data ): array {
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

		return array( $key, $name );
	}

	/**
	 * Сообщение о занятом ключе предмета — с подсказкой, что делать (A6).
	 *
	 * Импорт умеет только создавать новый предмет; merge/update в сценарии
	 * «перенос на чистый сайт» намеренно не поддержан. Поэтому текст обязан
	 * объяснить выход, а не просто констатировать конфликт.
	 *
	 * @param string $key Занятый ключ
	 *
	 * @return string
	 */
	private function duplicateKeyMessage( string $key ): string {
		return "Предмет с ключом «{$key}» уже существует на этом сайте. "
			. 'Повторный импорт того же предмета не поддерживается: импорт всегда создаёт новый предмет, '
			. 'а не дополняет существующий. Удалите (или переименуйте ключ) предмета на этом сайте '
			. 'и повторите импорт.';
	}

	/**
	 * Считает объекты по разделам файла импорта.
	 *
	 * @param array $data Декодированный JSON-массив
	 *
	 * @return array<string, int>
	 */
	private function countSections( array $data ): array {
		$counts = array(
			'taxonomies'   => count( (array) ( $data['taxonomies'] ?? array() ) ),
			'metaboxes'    => count( (array) ( $data['metaboxes'] ?? array() ) ),
			'boilerplates' => 0,
			'terms'        => 0,
		);

		foreach ( (array) ( $data['boilerplates'] ?? array() ) as $bp_list ) {
			$counts['boilerplates'] += count( (array) $bp_list );
		}

		foreach ( (array) ( $data['terms'] ?? array() ) as $term_list ) {
			$counts['terms'] += count( (array) $term_list );
		}

		foreach ( (array) ( $data['posts'] ?? array() ) as $post_type => $post_list ) {
			$counts[ (string) $post_type ] = count( (array) $post_list );
		}

		return $counts;
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
					slug:            sanitize_title( (string) $tax_slug ),
					name:            sanitize_text_field( $tax_data['name'] ?? '' ),
					subject_key:     $key,
					display_type:    sanitize_text_field( $tax_data['display_type'] ?? 'select' ),
					is_required:     (bool) ( $tax_data['is_required'] ?? false ),
					use_in_articles: (bool) ( $tax_data['use_in_articles'] ?? false ),
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
	 * @param array               $terms_by_taxonomy Массив [tax_slug => [term1, term2, ...]]
	 * @param ImportedEntitiesCollector $created           Журнал созданного (для отката)
	 *
	 * @return void
	 */
	private function importTerms( array $terms_by_taxonomy, ImportedEntitiesCollector $created ): void {
		foreach ( $terms_by_taxonomy as $tax_slug => $term_list ) {
			$taxonomy = sanitize_title( (string) $tax_slug );
			// ensureTaxonomy() — проверяет существование таксономии и регистрирует при необходимости
			$this->terms->ensureTaxonomy( $taxonomy );

			foreach ( (array) $term_list as $term_data ) {
				$name = sanitize_text_field( $term_data['name'] ?? '' );
				if ( empty( $name ) ) {
					continue;
				}
				// insert() возвращает ID только для реально созданного термина —
				// переиспользованные существующие в журнал отката не попадают.
				$created->addTerm(
					$this->terms->insert(
						$name,
						$taxonomy,
						array(
							'slug'        => sanitize_title( $term_data['slug'] ?? $name ),
							'description' => sanitize_text_field( $term_data['description'] ?? '' ),
						)
					),
					$taxonomy
				);
			}
		}
	}

	/**
	 * Импортирует посты (задания и статьи).
	 *
	 * @param array               $posts_data Массив постов, сгруппированных по типам [post_type => post_list]
	 * @param ImportedEntitiesCollector $created    Журнал созданного (для отката)
	 *
	 * @return void
	 */
	private function importPosts( array $posts_data, ImportedEntitiesCollector $created ): void {
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

				$created->addPost( $post_id );

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
