<?php

declare( strict_types=1 );

namespace Unit\Services\Subject\Bundle;

use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\Bundle\ExportIdMapper;
use Inc\Services\Subject\Bundle\MediaSideloader;
use Inc\Services\Subject\Bundle\PostRestorer;
use Inc\Services\Subject\Bundle\ProblemDeduplicator;
use Inc\Services\Subject\Bundle\SubjectBundleImportService;
use Inc\Services\Subject\Import\ImportRollbackService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Восстановление предмета из пакета: совместимость формата, порядок графа,
 * резолв ссылок, дедуп глобального банка и откат.
 */
class SubjectBundleImportServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_taxonomies'] = array();
	}

	// ── Совместимость формата ────────────────────────────────────────

	public function test_rejects_incompatible_major_version(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/несовместим/u' );

		$this->makeService()->preview( $this->manifest( array( 'schema_version' => '2.0.0' ) ) );
	}

	public function test_rejects_manifest_without_version(): void {
		$manifest = $this->manifest();
		unset( $manifest['schema_version'] );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/нет версии формата/u' );

		$this->makeService()->preview( $manifest );
	}

	public function test_rejects_manifest_without_subject(): void {
		$manifest = $this->manifest();
		unset( $manifest['subject'] );

		$this->expectException( InvalidArgumentException::class );

		$this->makeService()->preview( $manifest );
	}

	// ── Предпросмотр ─────────────────────────────────────────────────

	public function test_preview_counts_every_section(): void {
		$report = $this->makeService()->preview( $this->manifest() );

		self::assertTrue( $report->dryRun );
		self::assertSame( 2, $report->counts['tasks'] );
		self::assertSame( 1, $report->counts['works'] );
		self::assertSame( 1, $report->counts['lessons'] );
		self::assertSame( 1, $report->counts['courses'] );
		self::assertSame( 1, $report->counts['problems'] );
	}

	public function test_preview_always_warns_that_progress_is_not_transferred(): void {
		$report = $this->makeService()->preview( $this->manifest() );

		$joined = implode( ' ', $report->warnings );
		self::assertStringContainsString( 'Прогресс обучения', $joined );
	}

	public function test_preview_warns_about_missing_scope(): void {
		$report = $this->makeService()->preview( $this->manifest( array(
			'scope' => array( 'curriculum' => false, 'media' => false, 'students' => false ),
		) ) );

		$joined = implode( ' ', $report->warnings );
		self::assertStringContainsString( 'нет учебной программы', $joined );
		self::assertStringContainsString( 'нет медиафайлов', $joined );
	}

	public function test_preview_blocks_import_on_existing_subject_key(): void {
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика' ) );

		$report = $this->makeService( subjects: $subjects )->preview( $this->manifest() );

		self::assertFalse( $report->isImportable() );
	}

	// ── Порядок восстановления и резолв ссылок ───────────────────────

	public function test_restores_sections_in_dependency_order_and_resolves_refs(): void {
		$restoredTypes = array();
		$restoredMeta  = array();
		$nextId        = 100;

		$restorer = $this->createMock( PostRestorer::class );
		$restorer->method( 'restore' )->willReturnCallback(
			function ( string $postType, array $data ) use ( &$restoredTypes, &$restoredMeta, &$nextId ): int {
				$restoredTypes[]                                     = $postType;
				$restoredMeta[ $data['_export_id'] ?? '?' ]          = $data['meta'] ?? array();
				return ++$nextId;
			}
		);

		$this->makeService( restorer: $restorer )->import(
			$this->manifest(),
			'/tmp/none',
			new ImportedEntitiesCollector(),
			new ExportIdMapper()
		);

		// Задания и задачи банка обязаны быть восстановлены раньше работы,
		// работа — раньше урока, урок — раньше курса.
		self::assertSame(
			array( 'math_tasks', 'math_tasks', 'fs_lms_problems', 'math_works', 'math_lessons', 'math_courses' ),
			$restoredTypes
		);

		// Ссылки работы разрешились в реальные новые ID (задание + задача банка).
		self::assertSame( array( 101, 103 ), $restoredMeta['works:70']['item_ids'] );

		// Шаг урока указывает на созданную работу.
		self::assertSame( 104, $restoredMeta['lessons:80']['steps'][0]['payload']['ref'] );

		// Модуль курса указывает на созданный урок.
		self::assertSame( array( 105 ), $restoredMeta['courses:90']['modules'][0]['lesson_ids'] );
	}

	public function test_reuses_existing_bank_problem_instead_of_duplicating(): void {
		$problems = $this->createMock( ProblemDeduplicator::class );
		$problems->method( 'findExisting' )->willReturn( 555 );
		// Переиспользованная задача не помечается — она не наша.
		$problems->expects( self::never() )->method( 'mark' );

		$restoredTypes = array();
		$nextId        = 100;

		$restorer = $this->createMock( PostRestorer::class );
		$restorer->method( 'restore' )->willReturnCallback(
			function ( string $postType, array $data ) use ( &$restoredTypes, &$nextId ): int {
				$restoredTypes[] = $postType;
				unset( $data );
				return ++$nextId;
			}
		);

		$created = new ImportedEntitiesCollector();
		$this->makeService( restorer: $restorer, problems: $problems )
			->import( $this->manifest(), '/tmp/none', $created, new ExportIdMapper() );

		self::assertNotContains( 'fs_lms_problems', $restoredTypes, 'дубликат в глобальном банке не создаётся' );
	}

	public function test_existing_subject_key_blocks_import(): void {
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика' ) );

		$this->expectException( InvalidArgumentException::class );

		$this->makeService( subjects: $subjects )->import(
			$this->manifest(),
			'/tmp/none',
			new ImportedEntitiesCollector(),
			new ExportIdMapper()
		);
	}

	// ── Откат ────────────────────────────────────────────────────────

	public function test_failed_import_triggers_rollback_of_created_entities(): void {
		$restorer = $this->createMock( PostRestorer::class );
		$restorer->method( 'restore' )->willThrowException( new RuntimeException( 'сбой вставки' ) );

		$captured = null;
		$rollback = $this->createMock( ImportRollbackService::class );
		$rollback->expects( self::once() )->method( 'undo' )->willReturnCallback(
			function ( ImportedEntitiesCollector $created ) use ( &$captured ): void {
				$captured = $created;
			}
		);

		try {
			$this->makeService( restorer: $restorer, rollback: $rollback )->import(
				$this->manifest(),
				'/tmp/none',
				new ImportedEntitiesCollector(),
				new ExportIdMapper()
			);
			self::fail( 'Ожидалось исключение импорта' );
		} catch ( RuntimeException ) {
			// Ошибка перебрасывается наружу — но БД уже почищена.
		}

		self::assertSame( array( 'math' ), $captured?->subjectKeys() );
	}

	/**
	 * Собирает сервис с моками по умолчанию.
	 */
	private function makeService(
		?SubjectRepository $subjects = null,
		?PostRestorer $restorer = null,
		?ProblemDeduplicator $problems = null,
		?ImportRollbackService $rollback = null
	): SubjectBundleImportService {
		if ( null === $subjects ) {
			$subjects = $this->createMock( SubjectRepository::class );
			$subjects->method( 'getByKey' )->willReturn( null );
			$subjects->method( 'save' )->willReturn( true );
		}

		if ( null === $problems ) {
			$problems = $this->createMock( ProblemDeduplicator::class );
			$problems->method( 'findExisting' )->willReturn( 0 );
		}

		$terms = $this->createMock( TermManager::class );
		$terms->method( 'insert' )->willReturn( 0 );

		$media = $this->createMock( MediaSideloader::class );
		$media->method( 'sideloadAll' )->willReturn( array(
			'map'      => new \Inc\Services\Subject\Bundle\MediaIdMap(),
			'warnings' => array(),
		) );

		return new SubjectBundleImportService(
			$subjects,
			$this->createMock( TaxonomyRepository::class ),
			$this->createMock( MetaBoxRepository::class ),
			$this->createMock( BoilerplateRepository::class ),
			$terms,
			$restorer ?? $this->createMock( PostRestorer::class ),
			$media,
			$problems,
			$rollback ?? $this->createMock( ImportRollbackService::class ),
		);
	}

	/**
	 * Манифест с полным графом ссылок: задание + задача банка → работа → урок → курс.
	 *
	 * @param array<string, mixed> $overrides Переопределения верхнего уровня
	 *
	 * @return array<string, mixed>
	 */
	private function manifest( array $overrides = array() ): array {
		return array_merge( array(
			'schema_version' => '1.0.0',
			'site_url'       => 'https://source.example',
			'scope'          => array( 'curriculum' => true, 'media' => true, 'students' => false ),
			'subject'        => array( 'key' => 'math', 'name' => 'Математика', 'hasBank' => true ),
			'taxonomies'     => array(),
			'metaboxes'      => array(),
			'boilerplates'   => array(),
			'terms'          => array(),
			'media'          => array(),
			'posts'          => array(
				'tasks'       => array(
					array( '_export_id' => 'tasks:11', 'post_title' => 'Задание 1', 'meta' => array() ),
					array( '_export_id' => 'tasks:12', 'post_title' => 'Задание 2', 'meta' => array() ),
				),
				'articles'    => array(),
				'problems'    => array(
					array( '_export_id' => 'problems:60', 'post_title' => 'Задача банка', 'meta' => array() ),
				),
				'works'       => array(
					array(
						'_export_id' => 'works:70',
						'post_title' => 'Работа',
						'meta'       => array( 'item_ids' => array( 'tasks:11', 'problems:60' ) ),
					),
				),
				'assessments' => array(),
				'lessons'     => array(
					array(
						'_export_id' => 'lessons:80',
						'post_title' => 'Урок',
						'meta'       => array(
							'steps' => array( array( 'type' => 'work', 'payload' => array( 'ref' => 'works:70' ) ) ),
						),
					),
				),
				'courses'     => array(
					array(
						'_export_id' => 'courses:90',
						'post_title' => 'Курс',
						'meta'       => array(
							'modules' => array( array( 'id' => 'm1', 'lesson_ids' => array( 'lessons:80' ) ) ),
						),
					),
				),
			),
		), $overrides );
	}
}
