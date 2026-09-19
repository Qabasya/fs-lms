<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Services\Task\TaskFileSchemeService;
use FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Ссылки на файлы заданий, оставшиеся с `http://` после переезда сайта на
 * HTTPS: для браузера это чужой origin, поэтому `download` у чипа файла
 * игнорируется и файл открывается во вкладке вместо скачивания.
 */
class TaskFileSchemeServiceTest extends TestCase {

	private FakeWpdb $wpdb;
	private TaskFileSchemeService $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();

		$this->wpdb                   = new FakeWpdb();
		$GLOBALS['wpdb']              = $this->wpdb;
		$GLOBALS['_fs_test_home_url'] = 'https://example.com';

		$this->service = new TaskFileSchemeService();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_home_url'] );
		$GLOBALS['wpdb'] = new \wpdb();
		parent::tearDown();
	}

	private function seedTask( int $id, array $meta ): void {
		fs_test_seed_post( array( 'ID' => $id, 'post_type' => 'inf_tasks' ) );
		update_post_meta( $id, PostMetaName::Meta->value, $meta );
	}

	private function meta( int $id ): array {
		$meta = get_post_meta( $id, PostMetaName::Meta->value, true );

		return is_array( $meta ) ? $meta : array();
	}

	public function test_own_link_is_switched_to_https(): void {
		$this->seedTask( 1, array( 'file' => 'http://example.com/uploads/900.txt' ) );
		$this->wpdb->queueCol( array( 1 ) );

		$result = $this->service->migrate();

		self::assertSame( 'https://example.com/uploads/900.txt', $this->meta( 1 )['file'] );
		self::assertSame( 1, $result['updated'] );
		self::assertSame( 1, $result['links'] );
	}

	public function test_all_file_fields_are_covered(): void {
		$this->seedTask( 2, array(
			'file'           => 'http://example.com/a.txt',
			'file_primary'   => 'http://example.com/b.txt',
			'file_secondary' => 'http://example.com/c.txt',
		) );
		$this->wpdb->queueCol( array( 2 ) );

		$result = $this->service->migrate();

		self::assertSame( 3, $result['links'] );
	}

	public function test_foreign_link_is_left_alone(): void {
		// Чужой домен переписывать нельзя: HTTPS там может не быть вовсе.
		$this->seedTask( 3, array( 'file' => 'http://drive.example.org/file.pdf' ) );
		$this->wpdb->queueCol( array( 3 ) );

		$result = $this->service->migrate();

		self::assertSame( 'http://drive.example.org/file.pdf', $this->meta( 3 )['file'] );
		self::assertSame( 0, $result['updated'] );
	}

	public function test_dry_run_changes_nothing(): void {
		$this->seedTask( 4, array( 'file' => 'http://example.com/a.txt' ) );
		$this->wpdb->queueCol( array( 4 ) );

		$result = $this->service->migrate( true );

		self::assertSame( 'http://example.com/a.txt', $this->meta( 4 )['file'] );
		self::assertSame( 1, $result['updated'] );
	}

	public function test_other_meta_keys_are_untouched(): void {
		$this->seedTask( 5, array(
			'file'           => 'http://example.com/a.txt',
			'task_condition' => '<p>Смотри http://example.com/страницу</p>',
		) );
		$this->wpdb->queueCol( array( 5 ) );

		$this->service->migrate();

		// Правятся только поля файлов: ссылка в тексте условия — авторская.
		self::assertStringContainsString( 'http://example.com/страницу', $this->meta( 5 )['task_condition'] );
	}

	public function test_http_site_is_a_no_op(): void {
		// Сайт ещё на http — переписывать нечего и не на что.
		$GLOBALS['_fs_test_home_url'] = 'http://example.com';

		self::assertSame(
			array( 'scanned' => 0, 'updated' => 0, 'links' => 0 ),
			$this->service->migrate()
		);
	}
}
