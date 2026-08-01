<?php

declare( strict_types=1 );

namespace Inc\Services\Subject\Bundle;

use Inc\DTO\Subject\BundleOptionsDTO;
use Inc\Enums\Subject\BundleSection;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Subject\PostTypeResolver;
use Inc\Services\Subject\SubjectExportService;
use InvalidArgumentException;

/**
 * Class SubjectBundleExportService
 *
 * Собирает полный пакет переноса предмета: манифест + физические медиафайлы (Этап 2).
 *
 * @package Inc\Services\Subject\Bundle
 *
 * ### Конвейер
 *
 * ```
 * структуры предмета → записи по разделам → подтягивание problems →
 * присвоение _export_id → ремап ссылок → сбор медиа → манифест
 * ```
 *
 * ### Почему ремап ссылок отдельным проходом
 *
 * Работа может ссылаться на задание, которое собрано позже неё, а урок — на
 * работу и на задачу глобального банка одновременно. Пока не собраны **все**
 * разделы, карта `WP ID → _export_id` неполна, и ремап на лету выбрасывал бы
 * валидные ссылки как нерезолвимые. Поэтому сначала полный сбор, потом единый
 * проход подмены.
 *
 * ### Прогресс не переносится
 *
 * Зафиксированное решение: пакет содержит только контент и (опционально)
 * учётки с зачислением. Посещаемость, сдачи, попытки, оценки и события обучения
 * не выгружаются — на целевом сайте история начинается с нуля.
 */
class SubjectBundleExportService {

	/**
	 * Таксономия тематик глобального банка задач.
	 */
	private const string PROBLEM_TAXONOMY = 'problem_tag';

	/**
	 * Конструктор.
	 *
	 * @param SubjectRepository     $subjects   Репозиторий предметов
	 * @param SubjectExportService  $structures Источник структур предмета
	 * @param PostCollector         $collector  Сборщик представлений записей
	 * @param MediaCollector        $media      Сборщик вложений
	 * @param TermManager           $terms      Менеджер терминов (тематики банка задач)
	 */
	public function __construct(
		private readonly SubjectRepository    $subjects,
		private readonly SubjectExportService $structures,
		private readonly PostCollector        $collector,
		private readonly MediaCollector       $media,
		private readonly TermManager          $terms,
	) {}

	/**
	 * Собирает манифест и список файлов для упаковки.
	 *
	 * @param string           $subjectKey Ключ предмета
	 * @param BundleOptionsDTO $options    Объём пакета
	 *
	 * @return array{
	 *     manifest: array<string, mixed>,
	 *     files: array<int, array{path: string, archive_path: string}>,
	 *     warnings: string[],
	 *     counts: array<string, int>
	 * }
	 *
	 * @throws InvalidArgumentException Предмет не найден
	 */
	public function build( string $subjectKey, BundleOptionsDTO $options ): array {
		$subject = $this->subjects->getByKey( $subjectKey );
		if ( null === $subject ) {
			throw new InvalidArgumentException( "Предмет «{$subjectKey}» не найден." );
		}

		$taxonomies = $this->structures->taxonomySlugs( $subjectKey );
		$sections   = $this->collectSections( $subjectKey, $options, $taxonomies );

		// Задачи глобального банка подтягиваются автоматически — только те,
		// на которые реально ссылаются собранные работы и контрольные.
		if ( $options->includeCurriculum ) {
			$sections[ BundleSection::Problems->value ] = $this->collectReferencedProblems( $sections );
		}

		$mapper   = $this->assignExportIds( $sections );
		$remapper = new RefRemapper();
		$posts    = $this->remapSections( $sections, $mapper, $remapper );

		$mediaResult = $options->includeMedia
			? $this->media->describe( $this->media->collectIds( $this->flatten( $sections ) ) )
			: array(
				'manifest' => array(),
				'files'    => array(),
				'missing'  => array(),
			);

		$structures = $this->structures->structures( $subjectKey );

		// Тематики банка задач живут вне предмета, но без них импортированные
		// задачи потеряют рубрикацию — переносим ровно использованные.
		$problemTags = $this->collectProblemTags( $sections[ BundleSection::Problems->value ] ?? array() );
		if ( array() !== $problemTags ) {
			$structures['terms'][ self::PROBLEM_TAXONOMY ] = $problemTags;
		}

		$manifest = array_merge(
			array(
				'schema_version' => BundleSchema::VERSION,
				'plugin_version' => defined( 'FS_LMS_VERSION' ) ? FS_LMS_VERSION : '',
				'exported_at'    => wp_date( 'Y-m-d H:i:s' ),
				'site_url'       => home_url(),
				'scope'          => $options->toArray(),
				'subject'        => array(
					'key'     => $subject->key,
					'name'    => $subject->name,
					'hasBank' => $subject->hasBank,
				),
			),
			$structures,
			array(
				'posts' => $posts,
				'media' => $mediaResult['manifest'],
			)
		);

		return array(
			'manifest' => $manifest,
			'files'    => $mediaResult['files'],
			'warnings' => array_merge( $mediaResult['missing'], $this->media->unresolvedUrls(), $remapper->droppedRefs() ),
			'counts'   => $this->counts( $posts, count( $mediaResult['manifest'] ) ),
		);
	}

	/**
	 * Собирает записи по всем разделам объёма (кроме глобальных).
	 *
	 * @param string           $subjectKey Ключ предмета
	 * @param BundleOptionsDTO $options    Объём пакета
	 * @param string[]         $taxonomies Слаги таксономий предмета
	 *
	 * @return array<string, array<int, array<string, mixed>>> [раздел => записи]
	 */
	private function collectSections( string $subjectKey, BundleOptionsDTO $options, array $taxonomies ): array {
		$sections = array();

		foreach ( $options->sections() as $section ) {
			if ( $section->isGlobal() ) {
				// Глобальный банк собирается не сплошняком, а по ссылкам.
				$sections[ $section->value ] = array();
				continue;
			}

			$sections[ $section->value ] = $this->collector->collect(
				$section->postType( $subjectKey ),
				$taxonomies
			);
		}

		return $sections;
	}

	/**
	 * Находит задачи глобального банка, на которые ссылается программа предмета.
	 *
	 * Критерий «глобальная задача» — ссылка, ведущая на запись типа
	 * {@see PostTypeResolver::PROBLEMS_CPT}. Проверяется реальный тип записи,
	 * а не «отсутствие в списке заданий предмета»: второй вариант принял бы за
	 * задачу банка любой мусорный ID из битой меты.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $sections Собранные разделы
	 *
	 * @return array<int, array<string, mixed>> Представления задач банка
	 */
	private function collectReferencedProblems( array $sections ): array {
		$candidates = array();

		foreach ( array( BundleSection::Works->value, BundleSection::Assessments->value, BundleSection::Lessons->value ) as $key ) {
			foreach ( $sections[ $key ] ?? array() as $post ) {
				$this->collectRefIds( (array) ( $post['meta'] ?? array() ), $candidates );
			}
		}

		$problemIds = array_values( array_filter(
			$candidates,
			static fn( int $id ): bool => PostTypeResolver::isProblemPostType( (string) get_post_type( $id ) )
		) );

		// Задачи банка живут вне предмета — таксономии предмета к ним неприменимы,
		// собственная таксономия problem_tag снимается как есть.
		return $this->collector->collectByIds( $problemIds, array( self::PROBLEM_TAXONOMY ) );
	}

	/**
	 * Тематики банка задач, реально использованные экспортируемыми задачами.
	 *
	 * Выгружать всю таксономию целиком нельзя: на сайте-источнике в ней может
	 * быть рубрикация десятков чужих предметов.
	 *
	 * @param array<int, array<string, mixed>> $problems Представления задач банка
	 *
	 * @return array<int, array<string, mixed>> Термины в формате раздела `terms`
	 */
	private function collectProblemTags( array $problems ): array {
		$usedSlugs = array();

		foreach ( $problems as $problem ) {
			foreach ( (array) ( $problem['terms'][ self::PROBLEM_TAXONOMY ] ?? array() ) as $slug ) {
				$usedSlugs[ (string) $slug ] = true;
			}
		}

		if ( array() === $usedSlugs ) {
			return array();
		}

		$terms = array();
		foreach ( $this->terms->getAll( self::PROBLEM_TAXONOMY ) as $term ) {
			if ( isset( $usedSlugs[ $term->slug ] ) ) {
				$terms[] = array(
					'name'        => $term->name,
					'slug'        => $term->slug,
					'description' => $term->description,
					'parent'      => $term->parent,
				);
			}
		}

		return $terms;
	}

	/**
	 * Рекурсивно собирает все ID из ссылочных полей меты.
	 *
	 * @param array $node Узел меты
	 * @param int[] $ids  Аккумулятор (по ссылке)
	 *
	 * @return void
	 */
	private function collectRefIds( array $node, array &$ids ): void {
		foreach ( $node as $key => $value ) {
			$name = (string) $key;

			if ( in_array( $name, array( 'item_ids', 'task_ids' ), true ) ) {
				foreach ( (array) $value as $candidate ) {
					$id = (int) $candidate;
					if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
				continue;
			}

			if ( 'ref' === $name ) {
				$id = (int) $value;
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$this->collectRefIds( $value, $ids );
			}
		}
	}

	/**
	 * Присваивает каждой собранной записи стабильный `_export_id`.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $sections Собранные разделы (по ссылке не нужно —
	 *                                                                  идентификаторы кладутся при ремапе)
	 *
	 * @return ExportIdMapper Заполненная карта
	 */
	private function assignExportIds( array $sections ): ExportIdMapper {
		$mapper = new ExportIdMapper();

		foreach ( $sections as $sectionValue => $posts ) {
			$section = BundleSection::from( (string) $sectionValue );
			foreach ( $posts as $post ) {
				$mapper->register( $section, (int) ( $post['source_id'] ?? 0 ) );
			}
		}

		return $mapper;
	}

	/**
	 * Проставляет `_export_id` и переписывает ссылки внутри меты.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $sections Собранные разделы
	 * @param ExportIdMapper                                  $mapper   Заполненная карта
	 * @param RefRemapper                                     $remapper Переписыватель ссылок
	 *
	 * @return array<string, array<int, array<string, mixed>>> Разделы, готовые к манифесту
	 */
	private function remapSections( array $sections, ExportIdMapper $mapper, RefRemapper $remapper ): array {
		$result = array();

		foreach ( $sections as $sectionValue => $posts ) {
			$result[ $sectionValue ] = array();

			foreach ( $posts as $post ) {
				$sourceId = (int) ( $post['source_id'] ?? 0 );

				$post[ BundleSchema::EXPORT_ID ] = $mapper->toExportId( $sourceId );
				$post['meta']                    = $remapper->toExportIds( (array) ( $post['meta'] ?? array() ), $mapper );

				// source_id в манифесте не нужен: ссылки уже переведены в _export_id,
				// а исходный WP ID на целевом сайте только вводит в заблуждение.
				unset( $post['source_id'] );

				$result[ $sectionValue ][] = $post;
			}
		}

		return $result;
	}

	/**
	 * Плоский список всех записей всех разделов.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $sections Разделы
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten( array $sections ): array {
		$flat = array();

		foreach ( $sections as $posts ) {
			foreach ( $posts as $post ) {
				$flat[] = $post;
			}
		}

		return $flat;
	}

	/**
	 * Счётчики объёма пакета для отчёта и лога.
	 *
	 * @param array<string, array> $posts      Разделы записей
	 * @param int                  $mediaCount Число медиафайлов
	 *
	 * @return array<string, int>
	 */
	private function counts( array $posts, int $mediaCount ): array {
		$counts = array();

		foreach ( $posts as $section => $list ) {
			$counts[ (string) $section ] = count( $list );
		}

		$counts['media'] = $mediaCount;

		return $counts;
	}
}
