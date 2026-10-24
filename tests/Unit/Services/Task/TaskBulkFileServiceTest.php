<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\TaskBulkFileService;
use PHPUnit\Framework\TestCase;

/**
 * Какие ссылки массовая правка считает «своими файлами» и переименовывает.
 */
class TaskBulkFileServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_upload_dir'] = array(
			'baseurl' => 'https://site.example/wp-content/uploads',
			'basedir' => '/var/www/uploads',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_upload_dir'] );
		parent::tearDown();
	}

	public function test_url_inside_uploads_resolves_to_path_regardless_of_scheme(): void {
		$service = new TaskBulkFileService();

		self::assertSame( '/var/www/uploads/2021/05/24-123123.txt', $service->urlToPath( 'http://site.example/wp-content/uploads/2021/05/24-123123.txt' ) );
		self::assertSame( '/var/www/uploads/a b.txt', $service->urlToPath( 'https://site.example/wp-content/uploads/a%20b.txt' ) );
	}

	public function test_foreign_and_traversal_urls_are_not_ours(): void {
		$service = new TaskBulkFileService();

		self::assertNull( $service->urlToPath( 'https://other.example/wp-content/uploads/x.txt' ) );
		self::assertNull( $service->urlToPath( 'https://site.example/wp-content/uploads/../wp-config.php' ) );
		self::assertNull( $service->urlToPath( 'https://site.example/other/x.txt' ) );
	}

	public function test_plan_ignores_tasks_without_local_files(): void {
		$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://other.example/x.txt', 'task_condition' => 'x' ), '24003', 1 );

		self::assertSame( array(), $plan['ops'] );
		self::assertSame( array(), $plan['warnings'] );
	}
}
