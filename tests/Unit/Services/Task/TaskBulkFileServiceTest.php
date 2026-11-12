<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Services\Task\TaskBulkFileService;
use PHPUnit\Framework\TestCase;

/**
 * Какие ссылки массовая правка считает «своими файлами» и переименовывает.
 */
class TaskBulkFileServiceTest extends TestCase {
	public function test_move_does_not_overwrite_a_file_created_after_planning(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/original.txt', 'original' );
		file_put_contents( $root . '/1000.txt', 'other' );
		try {
			$op = array( 'from' => $root . '/original.txt', 'to' => $root . '/1000.txt', 'mode' => TaskBulkFileService::MODE_MOVE, 'attachment_id' => 0 );
			try {
				( new TaskBulkFileService() )->execute( array( $op ) );
				self::fail( 'A new target must not be overwritten.' );
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'other', file_get_contents( $root . '/1000.txt' ) );
				self::assertSame( 'original', file_get_contents( $root . '/original.txt' ) );
			}
		} finally {
			@unlink( $root . '/original.txt' );
			@unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_replacing_a_published_file_keeps_old_named_copy_available(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root . '/fs-lms/task-files/inf/42', 0755, true );
		file_put_contents( $root . '/fs-lms/task-files/inf/42/1000.txt', 'old' );
		file_put_contents( $root . '/new.txt', 'new' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$service = new TaskBulkFileService();
			$plan = $service->plan( array( 'file' => 'https://site.example/wp-content/uploads/new.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array( $root . '/fs-lms/task-files/inf/42/v2/1000.txt' ), array_column( $plan['ops'], 'to' ) );
			$service->execute( $plan['ops'] );
			self::assertSame( 'old', file_get_contents( $root . '/fs-lms/task-files/inf/42/1000.txt' ) );
			self::assertSame( 'new', file_get_contents( $root . '/fs-lms/task-files/inf/42/v2/1000.txt' ) );
		} finally {
			@unlink( $root . '/fs-lms/task-files/inf/42/v2/1000.txt' );
			@rmdir( $root . '/fs-lms/task-files/inf/42/v2' );
			unlink( $root . '/fs-lms/task-files/inf/42/1000.txt' );
			unlink( $root . '/new.txt' );
			rmdir( $root . '/fs-lms/task-files/inf/42' );
			rmdir( $root . '/fs-lms/task-files/inf' );
			rmdir( $root . '/fs-lms/task-files' );
			rmdir( $root . '/fs-lms' );
			rmdir( $root );
		}
	}

	public function test_subject_key_with_hyphen_has_a_safe_task_directory(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/old.txt', 'old' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://site.example/wp-content/uploads/old.txt' ), '1000', 42, true, 'comp-sci' );
			self::assertSame( array( $root . '/fs-lms/task-files/comp-sci/42/1000.txt' ), array_column( $plan['ops'], 'to' ) );
		} finally {
			unlink( $root . '/old.txt' );
			rmdir( $root );
		}
	}

	public function test_existing_numbered_source_is_copied_into_task_directory(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/1000.txt', 'shared' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://site.example/wp-content/uploads/1000.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array( $root . '/fs-lms/task-files/inf/42/1000.txt' ), array_column( $plan['ops'], 'to' ) );
			self::assertSame( TaskBulkFileService::MODE_COPY, $plan['ops'][0]['mode'] );
		} finally {
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_copy_rejects_symlink_created_after_plan(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		$outside = sys_get_temp_dir() . '/fs-lms-outside-' . uniqid();
		mkdir( $root );
		mkdir( $outside );
		mkdir( $outside . '/task-files/inf/42', 0755, true );
		file_put_contents( $root . '/source.txt', 'source' );
		file_put_contents( $root . '/1000.txt', 'other' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$service = new TaskBulkFileService();
			$plan = $service->plan( array( 'file' => 'https://site.example/wp-content/uploads/source.txt' ), '1000', 42, true, 'inf' );
			symlink( $outside, $root . '/fs-lms' );
			try {
				$service->execute( $plan['ops'] );
				self::fail( 'The destination must remain inside uploads.' );
			} catch ( \RuntimeException $e ) {
				self::assertFileDoesNotExist( $outside . '/task-files/inf/42/1000.txt' );
			}
		} finally {
			@unlink( $outside . '/task-files/inf/42/1000.txt' );
			@unlink( $root . '/fs-lms' );
			rmdir( $outside . '/task-files/inf/42' );
			rmdir( $outside . '/task-files/inf' );
			rmdir( $outside . '/task-files' );
			rmdir( $outside );
			unlink( $root . '/source.txt' );
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_copy_creates_safe_task_directory_for_collision(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/source.txt', 'source' );
		file_put_contents( $root . '/1000.txt', 'other' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$service = new TaskBulkFileService();
			$plan = $service->plan( array( 'file' => 'https://site.example/wp-content/uploads/source.txt' ), '1000', 42, true, 'inf' );
			self::assertDirectoryDoesNotExist( $root . '/fs-lms' );
			$service->execute( $plan['ops'] );
			self::assertSame( 'source', file_get_contents( $root . '/fs-lms/task-files/inf/42/1000.txt' ) );
			self::assertSame( 'other', file_get_contents( $root . '/1000.txt' ) );
		} finally {
			@unlink( $root . '/fs-lms/task-files/inf/42/1000.txt' );
			@rmdir( $root . '/fs-lms/task-files/inf/42' );
			@rmdir( $root . '/fs-lms/task-files/inf' );
			@rmdir( $root . '/fs-lms/task-files' );
			@rmdir( $root . '/fs-lms' );
			unlink( $root . '/source.txt' );
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_same_source_in_multiple_fields_uses_one_name_and_one_copy(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/one.txt', 'one' );
		file_put_contents( $root . '/two.txt', 'two' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$base = 'https://site.example/wp-content/uploads/';
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => $base . 'one.txt', 'file_primary' => $base . 'one.txt', 'file_secondary' => $base . 'two.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array( $root . '/fs-lms/task-files/inf/42/1000.txt', $root . '/fs-lms/task-files/inf/42/1000-2.txt' ), array_column( $plan['ops'], 'to' ) );
			self::assertSame( array( 'file', 'file_primary' ), $plan['ops'][0]['keys'] );
		} finally {
			unlink( $root . '/one.txt' );
			unlink( $root . '/two.txt' );
			rmdir( $root );
		}
	}

	public function test_copy_does_not_overwrite_a_file_created_after_planning(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/original.txt', 'original' );
		file_put_contents( $root . '/1000.txt', 'other' );
		try {
			$op = array( 'from' => $root . '/original.txt', 'to' => $root . '/1000.txt', 'mode' => TaskBulkFileService::MODE_COPY, 'attachment_id' => 0 );
			try {
				( new TaskBulkFileService() )->execute( array( $op ) );
				self::fail( 'A file created after planning must not be overwritten.' );
			} catch ( \RuntimeException $e ) {
				self::assertSame( 'other', file_get_contents( $root . '/1000.txt' ) );
				self::assertSame( 'original', file_get_contents( $root . '/original.txt' ) );
			}
		} finally {
			unlink( $root . '/original.txt' );
			unlink( $root . '/1000.txt' );
			rmdir( $root );
		}
	}

	public function test_adding_a_file_keeps_existing_numbered_file_names(): void {
		$root = sys_get_temp_dir() . '/fs-lms-upload-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/new.txt', 'new' );
		mkdir( $root . '/fs-lms/task-files/inf/42', 0755, true );
		file_put_contents( $root . '/fs-lms/task-files/inf/42/1000.txt', 'old' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$base = 'https://site.example/wp-content/uploads/';
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => $base . 'new.txt', 'file_primary' => $base . 'fs-lms/task-files/inf/42/1000.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array( $root . '/fs-lms/task-files/inf/42/1000-2.txt' ), array_column( $plan['ops'], 'to' ) );
			self::assertSame( array( 'file' ), array_column( $plan['ops'], 'key' ) );
		} finally {
			unlink( $root . '/new.txt' );
			unlink( $root . '/fs-lms/task-files/inf/42/1000.txt' );
			rmdir( $root . '/fs-lms/task-files/inf/42' );
			rmdir( $root . '/fs-lms/task-files/inf' );
			rmdir( $root . '/fs-lms/task-files' );
			rmdir( $root . '/fs-lms' );
			rmdir( $root );
		}
	}

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
				$dir = $preview ? $root . '/fs-lms/task-files/inf/42' : $root;
				self::assertSame( array( $dir . '/1000.txt', $dir . '/1000-2.txt' ), array_column( $plan['ops'], 'to' ) );
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
			self::assertSame( array( $root . '/fs-lms/task-files/inf/42/1000.txt', $root . '/fs-lms/task-files/inf/42/1000-2.txt', $root . '/fs-lms/task-files/inf/42/1000-3.pdf' ), array_column( $plan['ops'], 'to' ) );
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
			$valid = $service->plan( array( 'file' => $base . 'source.txt' ), '1000', 1, true, 'inf' );
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
			self::assertSame( $root . '/fs-lms/task-files/inf/42/v2/1000.txt', $occupied['ops'][0]['to'] );
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
		mkdir( $root . '/fs-lms/task-files/inf/42', 0755, true );
		file_put_contents( $root . '/fs-lms/task-files/inf/42/1000.txt', 'original' );
		$GLOBALS['_fs_test_upload_dir']['basedir'] = $root;
		try {
			$plan = ( new TaskBulkFileService() )->plan( array( 'file' => 'https://site.example/wp-content/uploads/fs-lms/task-files/inf/42/1000.txt' ), '1000', 42, true, 'inf' );
			self::assertSame( array(), $plan['ops'] );
			self::assertSame( array(), $plan['warnings'] );
		} finally {
			unlink( $root . '/fs-lms/task-files/inf/42/1000.txt' );
			rmdir( $root . '/fs-lms/task-files/inf/42' );
			rmdir( $root . '/fs-lms/task-files/inf' );
			rmdir( $root . '/fs-lms/task-files' );
			rmdir( $root . '/fs-lms' );
			rmdir( $root );
		}
	}

}
