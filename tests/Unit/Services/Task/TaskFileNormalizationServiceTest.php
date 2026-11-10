<?php

declare( strict_types=1 );

namespace Unit\Services\Task;

use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Managers\Wp\MediaManager;
use Inc\Services\Task\TaskBulkFileService;
use Inc\Services\Task\TaskFileNormalizationService;
use PHPUnit\Framework\TestCase;

class TaskFileNormalizationServiceTest extends TestCase {
	public function test_published_public_task_is_planned_with_original_preserved(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->with( 42 )->willReturn( new \WP_Post( array(
			'ID' => 42, 'post_type' => 'inf_tasks', 'post_status' => 'publish', 'post_name' => '1000',
		) ) );
		$posts->method( 'getMeta' )->with( 42, PostMetaName::Meta->value )->willReturn( array( 'file' => 'https://site.example/a.txt' ) );
		$files = $this->createMock( TaskBulkFileService::class );
		$files->expects( self::once() )->method( 'plan' )->with( array( 'file' => 'https://site.example/a.txt' ), '1000', 42, true, 'inf' )
			->willReturn( array( 'ops' => array( array( 'key' => 'file' ) ), 'warnings' => array() ) );
		$result = ( new TaskFileNormalizationService( $posts, $files, $this->createStub( MediaManager::class ) ) )->preview( 42 );
		self::assertSame( array( array( 'key' => 'file' ) ), $result['ops'] );
	}

	public function test_private_draft_and_non_numeric_slug_are_not_planned(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'get' )->willReturnMap( array(
			array( 1, new \WP_Post( array( 'ID' => 1, 'post_type' => 'fs_lms_problems', 'post_status' => 'publish', 'post_name' => '1000' ) ) ),
			array( 2, new \WP_Post( array( 'ID' => 2, 'post_type' => 'inf_tasks', 'post_status' => 'draft', 'post_name' => '1000' ) ) ),
			array( 3, new \WP_Post( array( 'ID' => 3, 'post_type' => 'inf_tasks', 'post_status' => 'publish', 'post_name' => 'invalid' ) ) ),
		) );
		$posts->expects( self::never() )->method( 'getMeta' );
		$files = $this->createMock( TaskBulkFileService::class );
		$files->expects( self::never() )->method( 'plan' );
		$service = new TaskFileNormalizationService( $posts, $files, $this->createStub( MediaManager::class ) );
		foreach ( array( 1, 2, 3 ) as $id ) {
			$result = $service->preview( $id );
			self::assertSame( array(), $result['ops'] );
			self::assertNotEmpty( $result['warnings'] );
		}
	}

	public function test_material_report_identifies_available_and_missing_attachments_without_mutation(): void {
		$posts = $this->createStub( PostManager::class );
		$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'inf_tasks', 'post_status' => 'publish', 'post_name' => '1000' ) ) );
		$posts->method( 'getMeta' )->willReturn( array( 'task_materials' => array( 'attachment_ids' => array( 5, 5, 9 ) ) ) );
		$files = $this->createMock( TaskBulkFileService::class );
		$files->method( 'plan' )->willReturn( array( 'ops' => array(), 'warnings' => array() ) );
		$files->method( 'inspectLocalUrl' )->with( 'https://site.example/a.txt' )->willReturn( '/uploads/a.txt' );
		$media = $this->createMock( MediaManager::class );
		$media->expects( self::exactly( 2 ) )->method( 'describe' )->willReturnMap( array(
			array( 5, array( 'url' => 'https://site.example/a.txt', 'name' => 'Задание', 'mime' => 'text/plain' ) ),
			array( 9, null ),
		) );
		$result = ( new TaskFileNormalizationService( $posts, $files, $media ) )->preview( 42 );
		self::assertSame( array( array( 'attachment_id' => 5, 'url' => 'https://site.example/a.txt', 'name' => 'Задание', 'path' => '/uploads/a.txt' ) ), $result['materials'] );
		self::assertNotEmpty( $result['warnings'] );
	}

	public function test_shared_local_source_is_reported_once_for_field_and_attachment_ids(): void {
		$root = sys_get_temp_dir() . '/fs-lms-material-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/source.txt', 'original' );
		$GLOBALS['_fs_test_upload_dir'] = array( 'basedir' => $root, 'baseurl' => 'https://site.example/uploads' );
		try {
			$url = 'https://site.example/uploads/source.txt';
			$posts = $this->createStub( PostManager::class );
			$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'inf_tasks', 'post_status' => 'publish', 'post_name' => '1000' ) ) );
			$posts->method( 'getMeta' )->willReturn( array( 'file' => $url, 'task_materials' => array( 'attachment_ids' => array( 5, 6 ) ) ) );
			$media = $this->createStub( MediaManager::class );
			$media->method( 'describe' )->willReturn( array( 'url' => $url, 'name' => 'Материал', 'mime' => 'text/plain' ) );
			$result = ( new TaskFileNormalizationService( $posts, new TaskBulkFileService(), $media ) )->preview( 42 );
			self::assertSame( array( array( 'path' => $root . '/source.txt', 'references' => array( array( 'field' => 'file' ), array( 'attachment_id' => 5 ), array( 'attachment_id' => 6 ) ) ) ), $result['sources'] );
			self::assertStringContainsString( 'нескольких', implode( ' ', $result['warnings'] ) );
			self::assertSame( 'original', file_get_contents( $root . '/source.txt' ) );
		} finally {
			unset( $GLOBALS['_fs_test_upload_dir'] );
			unlink( $root . '/source.txt' );
			rmdir( $root );
		}
	}

	public function test_hard_links_share_a_source_but_distinct_files_do_not(): void {
		$root = sys_get_temp_dir() . '/fs-lms-material-' . uniqid();
		mkdir( $root );
		file_put_contents( $root . '/one.txt', 'original' );
		link( $root . '/one.txt', $root . '/alias.txt' );
		file_put_contents( $root . '/other.txt', 'original' );
		$GLOBALS['_fs_test_upload_dir'] = array( 'basedir' => $root, 'baseurl' => 'https://site.example/uploads' );
		try {
			$base = 'https://site.example/uploads/';
			$posts = $this->createStub( PostManager::class );
			$posts->method( 'get' )->willReturn( new \WP_Post( array( 'ID' => 42, 'post_type' => 'inf_tasks', 'post_status' => 'publish', 'post_name' => '1000' ) ) );
			$posts->method( 'getMeta' )->willReturn( array( 'file' => $base . 'one.txt', 'file_primary' => $base . 'alias.txt', 'file_secondary' => $base . 'other.txt' ) );
			$result = ( new TaskFileNormalizationService( $posts, new TaskBulkFileService(), $this->createStub( MediaManager::class ) ) )->preview( 42 );
			self::assertCount( 2, $result['sources'] );
			self::assertSame( array( array( 'field' => 'file' ), array( 'field' => 'file_primary' ) ), $result['sources'][0]['references'] );
			self::assertSame( array( array( 'field' => 'file_secondary' ) ), $result['sources'][1]['references'] );
		} finally {
			unset( $GLOBALS['_fs_test_upload_dir'] );
			unlink( $root . '/one.txt' );
			unlink( $root . '/alias.txt' );
			unlink( $root . '/other.txt' );
			rmdir( $root );
		}
	}
}
