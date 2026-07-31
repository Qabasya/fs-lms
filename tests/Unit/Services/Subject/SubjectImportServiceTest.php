<?php

declare( strict_types=1 );

namespace Unit\Services\Subject;

use Inc\Services\Subject\Import\ImportedEntitiesCollector;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\BoilerplateRepository;
use Inc\Repositories\OptionsRepositories\MetaBoxRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Services\Subject\Import\ImportRollbackService;
use Inc\Services\Subject\SubjectImportService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Предпросмотр и откат импорта предмета (A5, A6, A7).
 */
class SubjectImportServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_taxonomies'] = array();
	}

	public function test_preview_counts_everything_that_would_be_created(): void {
		$service = $this->makeService();

		$report = $service->preview( $this->payload() );

		self::assertTrue( $report->dryRun );
		self::assertSame( 'math', $report->subjectKey );
		self::assertSame( 2, $report->counts['math_tasks'] );
		self::assertSame( 1, $report->counts['math_articles'] );
		self::assertSame( 2, $report->counts['terms'] );
		self::assertSame( 1, $report->counts['boilerplates'] );
		self::assertTrue( $report->isImportable() );
	}

	public function test_preview_reports_key_collision_with_actionable_hint(): void {
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturn( new SubjectDTO( 'math', 'Математика' ) );

		$report = $this->makeService( subjects: $subjects )->preview( $this->payload() );

		self::assertFalse( $report->isImportable() );
		self::assertCount( 1, $report->collisions );
		// A6: сообщение обязано объяснить выход, а не просто констатировать конфликт.
		self::assertStringContainsString( 'Удалите', $report->collisions[0] );
	}

	public function test_preview_writes_nothing(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( self::never() )->method( 'insert' );

		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'getByKey' )->willReturn( null );
		$subjects->expects( self::never() )->method( 'save' );

		$this->makeService( subjects: $subjects, posts: $posts )->preview( $this->payload() );
	}

	public function test_rejects_payload_without_subject_header(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->makeService()->preview( array( 'posts' => array() ) );
	}

	public function test_import_creates_subject_and_reports_counts(): void {
		$report = $this->makeService()->import( $this->payload() );

		self::assertFalse( $report->dryRun );
		self::assertSame( 'Математика', $report->subjectName );
		self::assertSame( 2, $report->counts['math_tasks'] );
	}

	public function test_failed_import_rolls_back_everything_created(): void {
		// Падение на вставке постов — после того, как предмет и термины созданы.
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'insert' )->willThrowException( new RuntimeException( 'БД недоступна' ) );

		$terms = $this->createMock( TermManager::class );
		$terms->method( 'insert' )->willReturn( 77 );

		$captured = null;
		$rollback = $this->createMock( ImportRollbackService::class );
		$rollback->expects( self::once() )
			->method( 'undo' )
			->willReturnCallback( function ( ImportedEntitiesCollector $created ) use ( &$captured ): void {
				$captured = $created;
			} );

		$service = $this->makeService( posts: $posts, terms: $terms, rollback: $rollback );

		try {
			$service->import( $this->payload() );
			self::fail( 'Ожидалось исключение импорта' );
		} catch ( RuntimeException ) {
			// Исключение перебрасывается наружу — но уже с чистой БД.
		}

		self::assertInstanceOf( ImportedEntitiesCollector::class, $captured );
		self::assertSame( array( 'math' ), $captured->subjectKeys() );
		self::assertSame( 2, $captured->counts()['terms'], 'созданные термины должны попасть в откат' );
	}

	public function test_reused_terms_are_not_scheduled_for_rollback(): void {
		// insert() вернул 0 — термин уже существовал, он не наш.
		$terms = $this->createMock( TermManager::class );
		$terms->method( 'insert' )->willReturn( 0 );

		$posts = $this->createMock( PostManager::class );
		$posts->method( 'insert' )->willThrowException( new RuntimeException( 'сбой' ) );

		$captured = null;
		$rollback = $this->createMock( ImportRollbackService::class );
		$rollback->method( 'undo' )->willReturnCallback(
			function ( ImportedEntitiesCollector $created ) use ( &$captured ): void {
				$captured = $created;
			}
		);

		try {
			$this->makeService( posts: $posts, terms: $terms, rollback: $rollback )->import( $this->payload() );
		} catch ( RuntimeException ) {
			// ожидаемо
		}

		self::assertSame( 0, $captured?->counts()['terms'] );
	}

	/**
	 * Собирает сервис с моками по умолчанию.
	 */
	private function makeService(
		?SubjectRepository $subjects = null,
		?PostManager $posts = null,
		?TermManager $terms = null,
		?ImportRollbackService $rollback = null
	): SubjectImportService {
		if ( null === $subjects ) {
			$subjects = $this->createMock( SubjectRepository::class );
			$subjects->method( 'getByKey' )->willReturn( null );
			$subjects->method( 'save' )->willReturn( true );
		}

		if ( null === $posts ) {
			$posts = $this->createMock( PostManager::class );
			$posts->method( 'insert' )->willReturn( 100 );
		}

		if ( null === $terms ) {
			$terms = $this->createMock( TermManager::class );
			$terms->method( 'insert' )->willReturn( 0 );
			$terms->method( 'exists' )->willReturn( false );
		}

		return new SubjectImportService(
			$subjects,
			$this->createMock( TaxonomyRepository::class ),
			$this->createMock( MetaBoxRepository::class ),
			$this->createMock( BoilerplateRepository::class ),
			$terms,
			$posts,
			$rollback ?? $this->createMock( ImportRollbackService::class ),
		);
	}

	/**
	 * Типовой файл импорта предмета.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return array(
			'subject'      => array( 'key' => 'math', 'name' => 'Математика', 'hasBank' => true ),
			'taxonomies'   => array( 'math_level' => array( 'name' => 'Уровень' ) ),
			'metaboxes'    => array( '1' => 'short-answer' ),
			'boilerplates' => array( 'n1' => array( array( 'uid' => 'bp1', 'title' => 'Шаблон', 'content' => '<p>x</p>' ) ) ),
			'terms'        => array(
				'math_task_number' => array(
					array( 'name' => '1', 'slug' => '1' ),
					array( 'name' => '2', 'slug' => '2' ),
				),
			),
			'posts'        => array(
				'math_tasks'    => array(
					array( 'post_title' => 'Задание 1' ),
					array( 'post_title' => 'Задание 2' ),
				),
				'math_articles' => array( array( 'post_title' => 'Статья' ) ),
			),
		);
	}
}
