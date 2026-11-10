<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\TaskBulkFileService;
use PHPUnit\Framework\TestCase;

/**
 * Какие ссылки массовая правка считает «своими файлами» и переименовывает.
 */
class TaskBulkFileServiceTest extends TestCase {
	public function test_skipped_file_does_not_take_first_number_in_preview_or_bulk_edit(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/a.txt', 'a' );
		file_put_contents( $root . '/b.txt', 'b' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$base = 'https://site.example/wp-content/uploads/';
			$meta = array( 'file' => $base . 'missing.txt', 'file_primary' => $base . 'a.txt', 'file_secondary' => $base . 'b.txt' );
			$service = new TaskBulkFileService();
			foreach ( array( true, false ) as $preview ) {
				$plan = $service->plan( $meta, '1000', 42, $preview, 'inf' );
				self::assertSame( array( $root . '/1000.txt', $root . '/1000-2.txt' ), array_column( $plan['ops'], 'to' ) );
				self::assertNotEmpty( $plan['warnings'] );
			}
		} finally {
			unlink( $root . '/a.txt' );
			unlink( $root . '/b.txt' );
			rmdir( $root );
		}
	}
	public function test_safe_preview_numbers_files_in_order_and_keeps_collision_checks(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		foreach ( array( 'a.txt', 'b.txt', 'c.pdf', '1000-2.txt' ) as $name ) {
			file_put_contents( $root . '/' . $name, $name );
		}
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$base = 'https://site.example/wp-content/uploads/';
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => $base . 'a.txt', 'file_primary' => $base . 'b.txt', 'file_secondary' => $base . 'c.pdf' ), '1000', 42, true, 'inf' );
			self::assertSame( array( $root . '/1000.txt', $root . '/fs-lms/task-files/inf/42/1000-2.txt', $root . '/1000-3.pdf' ), array_column( $plan['ops'], 'to' ) );
			self::assertSame( '1000-2.txt', file_get_contents( $root . '/1000-2.txt' ) );
		} finally {
			foreach ( array( 'a.txt', 'b.txt', 'c.pdf', '1000-2.txt' ) as $name ) {
				unlink( $root . '/' . $name );
			}
			rmdir( $root );
		}
	}

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

	public function test_safe_preview_rejects_encoded_traversal_and_external_symlinks(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		$outside = tempnam( sys_get_temp_dir(), 'fs-lms-outside-' );
		symlink( $outside, $root . '/linked.txt' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$service = new TaskBulkFileService();
			foreach ( array( '%2e%2e/secret.txt', 'linked.txt' ) as $path ) {
				$plan = $service->plan( array( 'file' => 'https://site.example/wp-content/uploads/' . $path ), '1000', 1, true );
				self::assertSame( array(), $plan['ops'] );
				self::assertNotEmpty( $plan['warnings'] );
			}
		} finally {
			unlink( $root . '/linked.txt' );
			unlink( $outside );
			rmdir( $root );
		}
	}

	public function test_safe_preview_preserves_valid_original_and_rejects_missing_extension(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/source.txt', 'данные' );
		file_put_contents( $root . '/1000', 'данные' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$service = new TaskBulkFileService();
			$base = 'https://site.example/wp-content/uploads/';
			self::assertSame( $root . '/source.txt', $service->inspectLocalUrl( $base . 'source.txt' ) );
			self::assertNull( $service->inspectLocalUrl( $base . 'missing.txt' ) );
			self::assertNull( $service->inspectLocalUrl( $base . '1000' ) );
			$valid = $service->plan( array( 'file' => $base . 'source.txt' ), '1000', 1, true );
			self::assertCount( 1, $valid['ops'] );
			self::assertSame( TaskBulkFileService::MODE_COPY, $valid['ops'][0]['mode'] );
			self::assertSame( 'данные', file_get_contents( $root . '/source.txt' ) );
			$invalid = $service->plan( array( 'file' => $base . '1000' ), '1000', 1, true );
			self::assertSame( array(), $invalid['ops'] );
			self::assertNotEmpty( $invalid['warnings'] );
		} finally {
			unlink( $root . '/source.txt' );
			unlink( $root . '/1000' );
			rmdir( $root );
		}
	}

	public function test_safe_preview_reports_occupied_target_without_touching_files(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/source.txt', 'original' );
		file_put_contents( $root . '/1000.txt', 'other' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://site.example/wp-content/uploads/source.txt' ), '1000', 1, true );
			self::assertSame( array(), $plan['ops'] );
			self::assertNotEmpty( $plan['warnings'] );
			self::assertSame( 'original', file_get_contents( $root . '/source.txt' ) );
			self::assertSame( 'other', file_get_contents( $root . '/1000.txt' ) );
		} finally {
			unlink( $root . '/source.txt' );
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_safe_preview_uses_task_directory_for_collisions_and_is_repeatable(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/source.txt', 'original' );
		file_put_contents( $root . '/1000.txt', 'other' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$service = new TaskBulkFileService();
			$meta = array( 'file' => 'https://site.example/wp-content/uploads/source.txt' );
			$first = $service->plan( $meta, '1000', 42, true, 'inf' );
			self::assertSame( $first, $service->plan( $meta, '1000', 42, true, 'inf' ) );
			self::assertSame( $root . '/fs-lms/task-files/inf/42/1000.txt', $first['ops'][0]['to'] );
			self::assertStringEndsWith( '/fs-lms/task-files/inf/42/1000.txt', $first['ops'][0]['new_url'] );
			self::assertDirectoryDoesNotExist( $root . '/fs-lms' );
			self::assertSame( 'other', file_get_contents( $root . '/1000.txt' ) );
			mkdir( $root . '/fs-lms/task-files/inf/42', 0777, true );
			file_put_contents( $root . '/fs-lms/task-files/inf/42/1000.txt', 'unrelated' );
			$occupied = $service->plan( $meta, '1000', 42, true, 'inf' );
			self::assertSame( array(), $occupied['ops'] );
			self::assertNotEmpty( $occupied['warnings'] );
			self::assertSame( 'unrelated', file_get_contents( $root . '/fs-lms/task-files/inf/42/1000.txt' ) );
		} finally {
			if ( is_file( $root . '/fs-lms/task-files/inf/42/1000.txt' ) ) {
				unlink( $root . '/fs-lms/task-files/inf/42/1000.txt' );
				rmdir( $root . '/fs-lms/task-files/inf/42' );
				rmdir( $root . '/fs-lms/task-files/inf' );
				rmdir( $root . '/fs-lms/task-files' );
				rmdir( $root . '/fs-lms' );
			}
			unlink( $root . '/source.txt' );
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_safe_preview_rejects_symlinked_collision_directory(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/source.txt', 'original' );
		symlink( $root . '/missing', $root . '/1000.txt' );
		symlink( sys_get_temp_dir(), $root . '/fs-lms' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://site.example/wp-content/uploads/source.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array(), $plan['ops'] );
			self::assertNotEmpty( $plan['warnings'] );
			self::assertSame( 'original', file_get_contents( $root . '/source.txt' ) );
		} finally {
			unlink( $root . '/source.txt' );
			unlink( $root . '/1000.txt' );
			unlink( $root . '/fs-lms' );
			rmdir( $root );
		}
	}

	public function test_safe_preview_skips_already_named_existing_file(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/1000.txt', 'original' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://site.example/wp-content/uploads/1000.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array(), $plan['ops'] );
			self::assertSame( array(), $plan['warnings'] );
		} finally {
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

}
