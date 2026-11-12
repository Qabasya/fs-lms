<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Subject\TaxonomyDataDTO;
use Inc\DTO\Task\TaskBulkRowDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\TermManager;
use Inc\Repositories\OptionsRepositories\TaxonomyRepository;
use Inc\Repositories\WPDBRepositories\TaskBulkSnapshotRepository;
use Inc\Services\Export\CsvExportService;
use Inc\Services\Export\OneTimeDownloadService;
use Inc\Services\Subject\ContentCacheService;
use Inc\Services\Task\TaskBulkCsvService;
use Inc\Services\Task\TaskBulkEditService;
use Inc\Services\Task\TaskBulkFileService;
use Inc\Services\Task\TaskFileSchemeService;
use Inc\Services\Task\TaskNumberService;
use PHPUnit\Framework\TestCase;

/**
 * Проверка строк массовой правки заданий: номера, серия, обмен и коллизии внутри партии.
 * Запись, откат и файлы проверены на стенде (сквозной прогон на MariaDB) — здесь только план.
 */
class TaskBulkEditServiceTest extends TestCase {
	public function test_bulk_number_change_keeps_draft_files_untouched(): void {
		$post = new \WP_Post( array( 'ID' => 1, 'post_name' => '1000', 'post_title' => '№ 1000. Задача', 'post_type' => 'inf_tasks', 'post_status' => 'draft' ) );
		$posts = $this->createStub( PostManager::class );
		$posts->method( 'findBySlug' )->willReturnCallback( static fn( string $type, string $slug ): ?\WP_Post => '1000' === $slug ? $post : null );
		$posts->method( 'getMeta' )->willReturn( array( 'file' => 'https://site.example/file.txt' ) );
		$terms = $this->createStub( TermManager::class );
		$terms->method( 'getPostTerms' )->willReturn( array( new \WP_Term( 11, '1', 'inf_task_number' ) ) );
		$terms->method( 'findBySlug' )->willReturn( new \WP_Term( 11, '1', 'inf_task_number' ) );
		$taxonomies = $this->createStub( TaxonomyRepository::class );
		$files = $this->createMock( TaskBulkFileService::class );
		$files->expects( self::never() )->method( 'plan' );
		$normalizer = $this->createMock( \Inc\Services\Task\TaskFileNormalizationService::class );
		$normalizer->expects( self::never() )->method( 'preview' );
		$service = new TaskBulkEditService( $posts, $terms, new TaskNumberService( $posts ), new TaskBulkCsvService( $posts, $terms, $taxonomies, new CsvExportService( $this->createStub( OneTimeDownloadService::class ) ) ), $files, $this->createStub( TaskBulkSnapshotRepository::class ), $this->createStub( TaskFileSchemeService::class ), $this->createStub( ContentCacheService::class ), $this->createStub( LogEventDispatcherInterface::class ), $this->createStub( ClockInterface::class ), $normalizer );
		self::assertSame( array(), $service->plan( 'inf', array( $this->row( '1000', '1001' ) ) )[0]->fileOps );
	}
	public function test_bulk_apply_updates_shared_file_and_material_and_snapshots_originals(): void {
		$post = new \WP_Post( array( 'ID' => 1, 'post_name' => '1000', 'post_type' => 'inf_tasks', 'post_title' => 'Задача', 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_status' => 'publish' ) );
		$before = array( 'file' => 'https://site.example/old.txt', 'task_materials' => array( 'attachment_ids' => array( 5 ) ) );
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturn( $post );
		$posts->method( 'getMeta' )->willReturn( $before );
		$posts->expects( self::once() )->method( 'updateMeta' )->with( 1, \Inc\Enums\Wp\PostMetaName::Meta->value, array( 'file' => 'https://site.example/1001.txt', 'task_materials' => array( 'attachment_ids' => array( 99 ) ) ) );
		$terms = $this->createStub( TermManager::class );
		$taxonomies = $this->createStub( TaxonomyRepository::class );
		$files = $this->createStub( TaskBulkFileService::class );
		$snapshots = $this->createMock( TaskBulkSnapshotRepository::class );
		$snapshots->expects( self::once() )->method( 'create' )->willReturnCallback( static function ( string $subject, int $user, array $items ): int {
			self::assertSame( array( 'file' => 'https://site.example/old.txt', 'task_materials' => array( 'attachment_ids' => array( 5 ) ) ), $items[0]['before']['file_meta'] );
			return 123;
		} );
		$media = $this->createMock( \Inc\Managers\Wp\MediaManager::class );
		$media->expects( self::once() )->method( 'createAttachmentForFile' )->with( '/new/1001.txt', 1 )->willReturn( 99 );
		$service = new TaskBulkEditService( $posts, $terms, new TaskNumberService( $posts ), new TaskBulkCsvService( $posts, $terms, $taxonomies, new CsvExportService( $this->createStub( OneTimeDownloadService::class ) ) ), $files, $snapshots, $this->createStub( TaskFileSchemeService::class ), $this->createStub( ContentCacheService::class ), $this->createStub( LogEventDispatcherInterface::class ), $this->createStub( ClockInterface::class ), null, $media );
		$op = array( 'key' => 'file', 'keys' => array( 'file', '__material_5' ), 'material_ids' => array( 5 ), 'from' => '/old.txt', 'to' => '/new/1001.txt', 'new_url' => 'https://site.example/1001.txt', 'mode' => TaskBulkFileService::MODE_COPY );
		$item = new \Inc\DTO\Task\TaskBulkPlanItemDTO( 1, 1, '1000', '1001', fileOps: array( $op ), changes: array( 'номер' ) );
		self::assertSame( 1, $service->apply( 'inf', array( $item ), 1 )['applied'] );
	}
	public function test_failed_file_execution_does_not_delete_a_foreign_target(): void {
		$posts = $this->createStub( PostManager::class );
		$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 1, 'post_name' => '1000', 'post_type' => 'inf_tasks', 'post_title' => 'Задача', 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_status' => 'publish' ) ) );
		$terms = $this->createStub( TermManager::class );
		$taxonomies = $this->createStub( TaxonomyRepository::class );
		$files = $this->createMock( TaskBulkFileService::class );
		$files->expects( self::once() )->method( 'execute' )->willThrowException( new \RuntimeException( 'target became occupied' ) );
		$files->expects( self::never() )->method( 'revert' );
		$service = new TaskBulkEditService( $posts, $terms, new TaskNumberService( $posts ), new TaskBulkCsvService( $posts, $terms, $taxonomies, new CsvExportService( $this->createStub( OneTimeDownloadService::class ) ) ), $files, $this->createStub( TaskBulkSnapshotRepository::class ), $this->createStub( TaskFileSchemeService::class ), $this->createStub( ContentCacheService::class ), $this->createStub( LogEventDispatcherInterface::class ), $this->createStub( ClockInterface::class ) );
		$item = new \Inc\DTO\Task\TaskBulkPlanItemDTO( 1, 1, '1000', '1001', fileOps: array( array( 'key' => 'file', 'mode' => TaskBulkFileService::MODE_COPY ) ), changes: array( 'номер' ) );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'target became occupied' );
		$service->apply( 'inf', array( $item ), 1 );
	}
	public function test_bulk_number_change_plans_fields_and_materials_together(): void {
		$post = new \WP_Post( array( 'ID' => 1, 'post_name' => '1000', 'post_title' => '№ 1000. Задача', 'post_type' => 'inf_tasks', 'post_status' => 'publish' ) );
		$posts = $this->createStub( PostManager::class );
		$posts->method( 'findBySlug' )->willReturnCallback( static fn( string $type, string $slug ): ?\WP_Post => '1000' === $slug ? $post : null );
		$posts->method( 'getMeta' )->willReturn( array( 'task_materials' => array( 'attachment_ids' => array( 5 ) ) ) );
		$terms = $this->createStub( TermManager::class );
		$terms->method( 'getPostTerms' )->willReturn( array( new \WP_Term( 11, '1', 'inf_task_number' ) ) );
		$terms->method( 'findBySlug' )->willReturn( new \WP_Term( 11, '1', 'inf_task_number' ) );
		$taxonomies = $this->createStub( TaxonomyRepository::class );
		$taxonomies->method( 'getBySubject' )->willReturn( array() );
		$normalizer = $this->createMock( \Inc\Services\Task\TaskFileNormalizationService::class );
		$op = array( 'key' => '__material_5', 'keys' => array( '__material_5' ), 'material_ids' => array( 5 ), 'from' => '/old', 'to' => '/new', 'new_url' => 'https://example.com/1001.txt', 'mode' => TaskBulkFileService::MODE_COPY );
		$normalizer->expects( self::once() )->method( 'preview' )->with( 1, '1001' )->willReturn( array( 'ops' => array( $op ), 'warnings' => array() ) );
		$service = new TaskBulkEditService( $posts, $terms, new TaskNumberService( $posts ), new TaskBulkCsvService( $posts, $terms, $taxonomies, new CsvExportService( $this->createStub( OneTimeDownloadService::class ) ) ), $this->createStub( TaskBulkFileService::class ), $this->createStub( TaskBulkSnapshotRepository::class ), $this->createStub( TaskFileSchemeService::class ), $this->createStub( ContentCacheService::class ), $this->createStub( LogEventDispatcherInterface::class ), $this->createStub( ClockInterface::class ), $normalizer, $this->createStub( \Inc\Managers\Wp\MediaManager::class ) );
		$items = $service->plan( 'inf', array( $this->row( '1000', '1001' ) ) );
		self::assertSame( array( 5 ), $items[0]->fileOps[0]['material_ids'] );
	}

	/** @var array<string, \WP_Post> Задания предмета по слагу. */
	private array $posts = array();

	/** @var array<string, \WP_Term> Термы номера задания по слагу. */
	private array $numberTerms = array();

	private TaskBulkEditService $service;
	private array $taskMeta = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_taxonomies'] = array( 'inf_task_number' );

		$this->posts       = array();
		$this->numberTerms = array(
			'inf_1' => new \WP_Term( 11, '1', 'inf_task_number' ),
			'inf_2' => new \WP_Term( 12, '2', 'inf_task_number' ),
		);

		$posts = $this->createMock( PostManager::class );
		$posts->method( 'findBySlug' )->willReturnCallback(
			function ( string $type, string $slug, int $exclude = 0 ): ?\WP_Post {
				$post = $this->posts[ $slug ] ?? null;

				return ( null !== $post && $post->ID !== $exclude ) ? $post : null;
			}
		);
		$posts->method( 'getMeta' )->willReturnCallback( fn(): array => $this->taskMeta );
		$posts->method( 'findSlugsByPrefix' )->willReturn( array() );

		$terms = $this->createMock( TermManager::class );
		$terms->method( 'getPostTerms' )->willReturn( array( $this->numberTerms['inf_1'] ) );
		$terms->method( 'findBySlug' )->willReturnCallback( fn( string $slug ): ?\WP_Term => $this->numberTerms[ $slug ] ?? null );

		$taxonomies = $this->createMock( TaxonomyRepository::class );
		$taxonomies->method( 'getBySubject' )->willReturn( array( new TaxonomyDataDTO( 'inf_task_number', 'Номер', 'inf', is_protected: true ) ) );

		$this->service = new TaskBulkEditService(
			$posts,
			$terms,
			new TaskNumberService( $posts ),
			new TaskBulkCsvService( $posts, $terms, $taxonomies, new CsvExportService( $this->createMock( OneTimeDownloadService::class ) ) ),
			new TaskBulkFileService(),
			$this->createMock( TaskBulkSnapshotRepository::class ),
			$this->createMock( TaskFileSchemeService::class ),
			$this->createMock( ContentCacheService::class ),
			$this->createMock( LogEventDispatcherInterface::class ),
			$this->createMock( ClockInterface::class ),
		);
	}

	public function test_bulk_number_change_preserves_original_file_url(): void {
		$root = sys_get_temp_dir() . '/fs-lms-bulk-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/1000.txt', 'original' );
		$GLOBALS['_fs_test_upload_dir'] = array( 'basedir' => $root, 'baseurl' => 'https://site.example/uploads' );
		try {
			$this->task( 1, '1000' );
			$this->taskMeta = array( 'file' => 'https://site.example/uploads/1000.txt' );
			$items = $this->service->plan( 'inf', array( $this->row( '1000', '1001' ) ) );
			self::assertSame( TaskBulkFileService::MODE_COPY, $items[0]->fileOps[0]['mode'] );
			self::assertSame( $root . '/fs-lms/task-files/inf/1/1001.txt', $items[0]->fileOps[0]['to'] );
		} finally {
			unset( $GLOBALS['_fs_test_upload_dir'] );
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}


	private function task( int $id, string $slug, string $title = 'Демо' ): void {
		$this->posts[ $slug ] = new \WP_Post( array( 'ID' => $id, 'post_name' => $slug, 'post_title' => "№ {$slug}. {$title}", 'post_type' => 'inf_tasks' ) );
	}

	private function row( string $old, string $new = '', string $taskNumber = '', string $title = '' ): TaskBulkRowDTO {
		return new TaskBulkRowDTO( line: 2, oldNumber: $old, newNumber: $new, title: $title, taskNumber: $taskNumber );
	}

	/** @return array<int, array<string, mixed>> */
	private function reports( array $rows ): array {
		return array_map( static fn( $i ): array => $i->toReport(), $this->service->plan( 'inf', $rows ) );
	}

	public function test_number_change_rebuilds_title_prefix(): void {
		$this->task( 1, '1000', 'Демоверсия 2021' );

		$report = $this->reports( array( $this->row( '1000', '1200' ) ) )[0];

		self::assertSame( 'ok', $report['status'] );
		self::assertContains( 'номер 1000 → 1200', $report['changes'] );
	}

	public function test_unchanged_row_is_skipped(): void {
		$this->task( 1, '1000' );

		self::assertSame( 'skip', $this->reports( array( $this->row( '1000' ) ) )[0]['status'] );
	}

	public function test_swap_of_two_numbers_is_allowed(): void {
		$this->task( 1, '1000' );
		$this->task( 2, '1001' );

		$reports = $this->reports( array( $this->row( '1000', '1001' ), $this->row( '1001', '1000' ) ) );

		self::assertSame( array( 'ok', 'ok' ), array_column( $reports, 'status' ) );
	}

	public function test_number_taken_by_a_task_outside_the_file_is_refused(): void {
		$this->task( 1, '1000' );
		$this->task( 2, '1001', 'Чужое' );

		$report = $this->reports( array( $this->row( '1000', '1001' ) ) )[0];

		self::assertSame( 'error', $report['status'] );
		self::assertStringContainsString( 'уже занят', $report['errors'][0] );
	}

	public function test_two_rows_cannot_take_the_same_number(): void {
		$this->task( 1, '1000' );
		$this->task( 2, '1001' );

		$reports = $this->reports( array( $this->row( '1000', '1500' ), $this->row( '1001', '1500' ) ) );

		self::assertSame( array( 'ok', 'error' ), array_column( $reports, 'status' ) );
	}

	public function test_row_failing_later_does_not_vacate_its_number(): void {
		$this->task( 1, '1000' );
		$this->task( 2, '1001' );

		// 1001 → «abc» невалидна и остаётся на месте, значит 1000 → 1001 занято.
		$reports = $this->reports( array( $this->row( '1000', '1001' ), $this->row( '1001', 'abc' ) ) );

		self::assertSame( array( 'error', 'error' ), array_column( $reports, 'status' ) );
	}

	public function test_number_must_belong_to_the_series(): void {
		$this->task( 1, '1000' );

		$report = $this->reports( array( $this->row( '1000', '2000' ) ) )[0];

		self::assertSame( 'error', $report['status'] );
		self::assertStringContainsString( 'Номер задания', $report['errors'][0], 'Подсказка про колонку «Номер задания».' );
	}

	public function test_moving_to_another_series_needs_existing_task_number(): void {
		$this->task( 1, '1000' );

		$ok = $this->service->plan( 'inf', array( $this->row( '1000', '2000', '2' ) ) )[0];
		self::assertTrue( $ok->isApplicable() );
		self::assertSame( 12, $ok->numberTerm );

		$missing = $this->reports( array( $this->row( '1000', '3000', '3' ) ) )[0];
		self::assertSame( 'error', $missing['status'], 'Серии 3 у предмета нет — терм не создаётся.' );
	}

	public function test_unknown_old_number_and_duplicate_rows_are_reported(): void {
		$this->task( 1, '1000' );

		$reports = $this->reports( array( $this->row( '9999', '1200' ), $this->row( '1000', '1200' ), $this->row( '1000', '1300' ) ) );

		self::assertSame( array( 'error', 'ok', 'error' ), array_column( $reports, 'status' ) );
		self::assertStringContainsString( 'не найдено', $reports[0]['errors'][0] );
		self::assertStringContainsString( 'повторно', $reports[2]['errors'][0] );
	}

	public function test_non_numeric_new_number_is_refused(): void {
		$this->task( 1, '1000' );

		self::assertSame( 'error', $this->reports( array( $this->row( '1000', '12a0' ) ) )[0]['status'] );
	}
}
