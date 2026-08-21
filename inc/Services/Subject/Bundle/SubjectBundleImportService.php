<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\Shared\SafeHtml;
use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\DTO\Subject\SubjectDTO;
use Inc\DTO\Subject\SubjectImportReportDTO;
use Inc\DTO\Task\TaskTemplateAssignmentDTO;
use Inc\DTO\Task\TaskTypeBoilerplateDTO;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\Enums\Subject\BundleSection;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\Import\ImportRollbackService;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectPagesService;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class SubjectBundleImportService
 *
 * Восстанавливает предмет из пакета переноса (Этапы 3–4).
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Порядок восстановления
 *
 * Строго по графу зависимостей ({@see BundleSection}): каждый следующий раздел
 * ссылается только на предыдущие, поэтому к моменту вставки записи все её
 * ссылки уже есть в карте `_export_id → новый WP ID`. Отдельный резолвер
 * зависимостей не нужен — порядок enum'а и есть топологическая сортировка.
 *
 * ```
 * медиа → структуры предмета → термины →
 * tasks → articles → problems → works → assessments → lessons → courses
 * ```
 *
 * Медиа заливается первым: тогда мета записей пишется уже с финальными
 * attachment ID, без второго прохода правки.
 *
 * ### Откат (A5)
 *
 * Всё созданное попадает в {@see ImportedEntitiesCollector}; при любой ошибке
 * {@see ImportRollbackService} удаляет ровно это. Переиспользованные сущности
 * (существовавший термин, найденная задача глобального банка) в журнал не
 * пишутся — откат обязан убрать только то, что создал сам.
 *
 * ### Прогресс не переносится
 *
 * Пакет не содержит посещаемости, сдач, попыток и оценок — на целевом сайте
 * ученик зачислен и видит курс, но проходит его с нуля.
 */
class SubjectBundleImportService {

	/**
	 * Через сколько вставленных записей сбрасывать object cache.
	 *
	 * Значение того же порядка, что и пагинация в `ArchiveExportProvider` (200):
	 * достаточно редко, чтобы сброс не съедал выигрыш от кэша, и достаточно
	 * часто, чтобы память не росла до конца импорта.
	 */
	private const int CACHE_FLUSH_BATCH = 200;

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository     $subjects     Репозиторий предметов
	 * @param TaxonomyRepository    $taxonomies   Репозиторий таксономий
	 * @param MetaBoxRepository     $metaboxes    Репозиторий привязок шаблонов
	 * @param BoilerplateRepository $boilerplates Репозиторий типовых условий
	 * @param TermManager           $terms        Менеджер терминов
	 * @param PostRestorer          $restorer     Создание записи из представления
	 * @param MediaSideloader       $media        Заливка медиафайлов
	 * @param ProblemDeduplicator   $problems     Дедуп глобального банка задач
	 * @param ImportRollbackService $rollback     Компенсирующее удаление
	 * @param SubjectPagesService   $pages        Страницы публичного лендинга предмета
	 */
	public function __construct(
		private readonly SubjectRepository     $subjects,
		private readonly TaxonomyRepository    $taxonomies,
		private readonly MetaBoxRepository     $metaboxes,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TermManager           $terms,
		private readonly PostRestorer          $restorer,
		private readonly MediaSideloader       $media,
		private readonly ProblemDeduplicator   $problems,
		private readonly ImportRollbackService $rollback,
		private readonly SubjectPagesService   $pages,
	) {}

	/**
	 * Предпросмотр: что даст импорт пакета, без единой записи в БД (A7).
	 *
	 * @param array $manifest Разобранный манифест
	 *
	 * @return SubjectImportReportDTO
	 *
	 * @throws InvalidArgumentException При несовместимом или битом манифесте
	 */
	public function preview( array $manifest ): SubjectImportReportDTO {
		[ $key, $name ] = $this->readHeader( $manifest );

		$collisions = array();
		$warnings   = array();

		if ( $this->subjects->getByKey( $key ) ) {
			$collisions[] = "Предмет с ключом «{$key}» уже существует на этом сайте. "
				. 'Импорт пакета всегда создаёт новый предмет — удалите существующий или измените его ключ.';
		}

		$sourceSite = (string) ( $manifest['site_url'] ?? '' );
		$reused     = 0;
		foreach ( (array) ( $manifest['posts'][ BundleSection::Problems->value ] ?? array() ) as $problem ) {
			$originId = ProblemDeduplicator::originId( $sourceSite, (string) ( $problem[ BundleSchema::EXPORT_ID ] ?? '' ) );
			if ( $this->problems->findExisting( $problem, $originId ) > 0 ) {
				++$reused;
			}
		}

		if ( $reused > 0 ) {
			$warnings[] = "Задач глобального банка уже есть на сайте: {$reused} — они будут переиспользованы, а не созданы заново.";
		}

		$scope = (array) ( $manifest['scope'] ?? array() );
		if ( empty( $scope['curriculum'] ) ) {
			$warnings[] = 'В пакете нет учебной программы (работы, контрольные, уроки, курсы) — только банк заданий и статей.';
		}
		if ( empty( $scope['media'] ) ) {
			$warnings[] = 'В пакете нет медиафайлов — ссылки на вложения в заданиях восстановлены не будут.';
		}

		$warnings[] = 'Прогресс обучения (посещаемость, сдачи, попытки, оценки) не переносится — история на этом сайте начнётся с нуля.';

		if ( ! empty( $scope['students'] ) ) {
			$warnings[] = 'Ученики и представители приедут со своими логинами и паролями с сайта-источника — '
				. 'входить они будут по прежним данным.';
		}

		return new SubjectImportReportDTO(
			dryRun:      true,
			subjectKey:  $key,
			subjectName: $name,
			counts:      $this->counts( $manifest ),
			collisions:  $collisions,
			warnings:    $warnings,
		);
	}

	/**
	 * Импортирует пакет.
	 *
	 * @param array               $manifest   Разобранный манифест
	 * @param string              $extractDir Каталог распаковки (для медиафайлов)
	 * @param ImportedEntitiesCollector $created    Журнал созданного (владелец — вызывающий код)
	 * @param ExportIdMapper      $mapper     Карта `_export_id → новый WP ID`; заполняется по ходу
	 *                                        импорта и нужна снаружи, чтобы группы учеников
	 *                                        смогли сослаться на импортированный курс
	 *
	 * @return SubjectImportReportDTO
	 *
	 * @throws InvalidArgumentException При несовместимом или битом манифесте
	 * @throws RuntimeException         При ошибке записи
	 */
	public function import(
		array $manifest,
		string $extractDir,
		ImportedEntitiesCollector $created,
		ExportIdMapper $mapper
	): SubjectImportReportDTO {
		[ $key, $name ] = $this->readHeader( $manifest );

		if ( $this->subjects->getByKey( $key ) ) {
			throw new InvalidArgumentException(
				"Предмет с ключом «{$key}» уже существует на этом сайте. Удалите его или измените ключ и повторите импорт."
			);
		}

		$warnings = array();

		try {
			// 1. Медиа — до записей, чтобы мета сразу писалась с финальными ID.
			$mediaResult = $this->media->sideloadAll(
				(array) ( $manifest['media'] ?? array() ),
				$extractDir,
				$created
			);
			$mediaMap    = $mediaResult['map'];
			$warnings    = array_merge( $warnings, $mediaResult['warnings'] );

			// 2. Предмет и его option-структуры.
			$hasBank = (bool) ( $manifest['subject']['hasBank'] ?? true );
			$subject = new SubjectDTO( $key, $name, hasBank: $hasBank );
			if ( ! $this->subjects->save( $subject ) ) {
				throw new RuntimeException( 'Не удалось создать запись предмета в БД.' );
			}
			$created->addSubject( $key );

			$this->importTaxonomies( $key, (array) ( $manifest['taxonomies'] ?? array() ) );
			$this->importMetaboxes( $key, (array) ( $manifest['metaboxes'] ?? array() ) );
			$this->importBoilerplates( $key, (array) ( $manifest['boilerplates'] ?? array() ) );
			$this->importTerms( (array) ( $manifest['terms'] ?? array() ), $created );

			// 3. Записи в топологическом порядке с ремапом ссылок.
			$remapper = new RefRemapper();

			$this->importSections( $manifest, $key, $mapper, $remapper, $mediaMap, $created );

			$warnings = array_merge( $warnings, $remapper->droppedRefs() );
		} catch ( \Throwable $e ) {
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
			counts:      $created->counts(),
			warnings:    $warnings,
		);
	}

	/**
	 * Восстанавливает записи всех разделов в порядке графа зависимостей.
	 *
	 * @param array               $manifest Манифест
	 * @param string              $key      Ключ предмета
	 * @param ExportIdMapper      $mapper   Карта `_export_id → новый WP ID`
	 * @param RefRemapper         $remapper Переписыватель ссылок
	 * @param MediaIdMap          $mediaMap Карта вложений
	 * @param ImportedEntitiesCollector $created  Журнал созданного
	 *
	 * @return void
	 */
	private function importSections(
		array $manifest,
		string $key,
		ExportIdMapper $mapper,
		RefRemapper $remapper,
		MediaIdMap $mediaMap,
		ImportedEntitiesCollector $created
	): void {
		$sourceSite = (string) ( $manifest['site_url'] ?? '' );
		$inserted   = 0;

		// Ссылки на медиа, вкраплённые в текст (картинка условия, URL-поля файлов),
		// переписываются подстрокой — ID-ремапом их не взять.
		$urlRewriter = new MediaUrlRewriter();
		$urlMap      = $urlRewriter->buildMap( (array) ( $manifest['media'] ?? array() ), $mediaMap );

		// Пересчёт term_count после каждой вставки — самая дорогая часть импорта
		// на сотнях записей; откладываем до конца всех разделов.
		wp_defer_term_counting( true );

		try {
			// BundleSection::cases() уже отсортирован топологически — см. enum.
			foreach ( BundleSection::cases() as $section ) {
				$posts = (array) ( $manifest['posts'][ $section->value ] ?? array() );

				foreach ( $posts as $post ) {
					$exportId = (string) ( $post[ BundleSchema::EXPORT_ID ] ?? '' );

					// Ссылки резолвятся ПЕРЕД вставкой: все предыдущие уровни уже в карте.
					$post['meta'] = $remapper->toPostIds( (array) ( $post['meta'] ?? array() ), $mapper, $mediaMap );
					$post         = $urlRewriter->rewritePost( $post, $urlMap );

					if ( $section->isGlobal() ) {
						$postId = $this->restoreProblem( $post, $sourceSite, $exportId, $section, $created );
					} else {
						$postId = $this->restorer->restore( $section->postType( $key ), $post, $created );
					}

					$mapper->bind( $exportId, $postId );

					// Батч: object cache растёт линейно по числу вставленных записей
					// и на крупном предмете упирается в memory_limit. Карты ID и
					// журнал отката живут в своих массивах и чисткой не задеваются.
					if ( 0 === ++$inserted % self::CACHE_FLUSH_BATCH ) {
						$this->relieveMemory();
					}
				}
			}
		} finally {
			wp_defer_term_counting( false );
		}
	}

	/**
	 * Сбрасывает накопленный object cache между батчами.
	 *
	 * @return void
	 */
	private function relieveMemory(): void {
		wp_cache_flush();

		// В режиме SAVEQUERIES wpdb копит все запросы — на импорте это заметный
		// расход памяти, а история запросов здесь никому не нужна.
		global $wpdb;
		if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			$wpdb->queries = array();
		}
	}

	/**
	 * Восстанавливает задачу глобального банка с дедупликацией.
	 *
	 * @param array               $problem    Представление задачи
	 * @param string              $sourceSite URL сайта-источника
	 * @param string              $exportId   `_export_id` задачи
	 * @param BundleSection       $section    Раздел (problems)
	 * @param ImportedEntitiesCollector $created    Журнал созданного
	 *
	 * @return int ID задачи на целевом сайте
	 */
	private function restoreProblem(
		array $problem,
		string $sourceSite,
		string $exportId,
		BundleSection $section,
		ImportedEntitiesCollector $created
	): int {
		$originId = ProblemDeduplicator::originId( $sourceSite, $exportId );

		// Найденная задача переиспользуется и в журнал отката НЕ попадает:
		// она существовала до этого импорта и принадлежит не нам.
		$existing = $this->problems->findExisting( $problem, $originId );
		if ( $existing > 0 ) {
			return $existing;
		}

		$postId = $this->restorer->restore( PostTypeResolver::problems(), $problem, $created );

		if ( $postId > 0 ) {
			$this->problems->mark( $postId, $problem, $originId );
		}

		return $postId;
	}

	/**
	 * Читает и валидирует шапку манифеста.
	 *
	 * @param array $manifest Манифест
	 *
	 * @return array{0: string, 1: string} [ключ, название]
	 *
	 * @throws InvalidArgumentException При несовместимом формате
	 */
	private function readHeader( array $manifest ): array {
		$version = (string) ( $manifest['schema_version'] ?? '' );

		if ( '' === $version ) {
			throw new InvalidArgumentException( 'В манифесте нет версии формата — файл не является пакетом переноса предмета.' );
		}

		if ( ! BundleSchema::isCompatible( $version ) ) {
			throw new InvalidArgumentException( sprintf(
				'Формат пакета (%s) несовместим с текущей версией плагина (%s). Обновите плагин или пересоберите пакет.',
				$version,
				BundleSchema::VERSION
			) );
		}

		if ( ! isset( $manifest['subject']['key'], $manifest['subject']['name'] ) ) {
			throw new InvalidArgumentException( 'В манифесте нет данных предмета.' );
		}

		$key  = sanitize_title( (string) $manifest['subject']['key'] );
		$name = sanitize_text_field( (string) $manifest['subject']['name'] );

		if ( '' === $key || '' === $name ) {
			throw new InvalidArgumentException( 'Ключ или название предмета в манифесте пусты.' );
		}

		return array( $key, $name );
	}

	/**
	 * Счётчики содержимого пакета для предпросмотра.
	 *
	 * @param array $manifest Манифест
	 *
	 * @return array<string, int>
	 */
	private function counts( array $manifest ): array {
		$counts = array(
			'taxonomies'   => count( (array) ( $manifest['taxonomies'] ?? array() ) ),
			'metaboxes'    => count( (array) ( $manifest['metaboxes'] ?? array() ) ),
			'boilerplates' => 0,
			'terms'        => 0,
			'media'        => count( (array) ( $manifest['media'] ?? array() ) ),
		);

		foreach ( (array) ( $manifest['boilerplates'] ?? array() ) as $list ) {
			$counts['boilerplates'] += count( (array) $list );
		}

		foreach ( (array) ( $manifest['terms'] ?? array() ) as $list ) {
			$counts['terms'] += count( (array) $list );
		}

		foreach ( BundleSection::cases() as $section ) {
			$counts[ $section->value ] = count( (array) ( $manifest['posts'][ $section->value ] ?? array() ) );
		}

		return $counts;
	}

	/**
	 * Импортирует пользовательские таксономии предмета.
	 *
	 * @param string $key        Ключ предмета
	 * @param array  $taxonomies [tax_slug => data]
	 *
	 * @return void
	 */
	private function importTaxonomies( string $key, array $taxonomies ): void {
		foreach ( $taxonomies as $slug => $data ) {
			$this->taxonomies->save( new TaxonomyDataDTO(
				slug:            sanitize_title( (string) $slug ),
				name:            sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				subject_key:     $key,
				display_type:    sanitize_text_field( (string) ( $data['display_type'] ?? 'select' ) ),
				is_required:     (bool) ( $data['is_required'] ?? false ),
				use_in_articles: (bool) ( $data['use_in_articles'] ?? false ),
			) );
		}
	}

	/**
	 * Импортирует привязки шаблонов метабоксов.
	 *
	 * @param string $key       Ключ предмета
	 * @param array  $metaboxes [task_number => template_id]
	 *
	 * @return void
	 */
	private function importMetaboxes( string $key, array $metaboxes ): void {
		foreach ( $metaboxes as $taskNumber => $templateId ) {
			$this->metaboxes->save( new TaskTemplateAssignmentDTO(
				$key,
				sanitize_text_field( (string) $taskNumber ),
				sanitize_text_field( (string) $templateId ),
			) );
		}
	}

	/**
	 * Импортирует типовые условия.
	 *
	 * @param string $key          Ключ предмета
	 * @param array  $boilerplates [term_slug => [bp, ...]]
	 *
	 * @return void
	 */
	private function importBoilerplates( string $key, array $boilerplates ): void {
		foreach ( $boilerplates as $termSlug => $list ) {
			foreach ( (array) $list as $bp ) {
				$this->boilerplates->save( new TaskTypeBoilerplateDTO(
					uid:         sanitize_text_field( (string) ( $bp['uid'] ?? uniqid( 'bp_', true ) ) ),
					subject_key: $key,
					term_slug:   sanitize_text_field( (string) $termSlug ),
					title:       sanitize_text_field( (string) ( $bp['title'] ?? '' ) ),
					content:     SafeHtml::post( (string) ( $bp['content'] ?? '' ) ),
					is_default:  (bool) ( $bp['is_default'] ?? false ),
				) );
			}
		}
	}

	/**
	 * Импортирует термины таксономий.
	 *
	 * @param array               $termsByTaxonomy [tax_slug => [term, ...]]
	 * @param ImportedEntitiesCollector $created         Журнал созданного
	 *
	 * @return void
	 */
	private function importTerms( array $termsByTaxonomy, ImportedEntitiesCollector $created ): void {
		foreach ( $termsByTaxonomy as $taxSlug => $list ) {
			$taxonomy = sanitize_title( (string) $taxSlug );
			$this->terms->ensureTaxonomy( $taxonomy );

			foreach ( (array) $list as $term ) {
				$name = sanitize_text_field( (string) ( $term['name'] ?? '' ) );
				if ( '' === $name ) {
					continue;
				}

				$created->addTerm(
					$this->terms->insert( $name, $taxonomy, array(
						'slug'        => sanitize_title( (string) ( $term['slug'] ?? $name ) ),
						'description' => sanitize_text_field( (string) ( $term['description'] ?? '' ) ),
					) ),
					$taxonomy
				);
			}
		}
	}
}
