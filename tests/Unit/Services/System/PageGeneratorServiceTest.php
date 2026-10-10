<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\System;

use Inc\Enums\Wp\PageRoutes;
use Inc\Managers\Wp\PostManager;
use Inc\Services\System\PageGeneratorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageGeneratorServiceTest extends TestCase {

	public function test_missing_page_is_inserted_and_verified(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( self::exactly( 2 ) )->method( 'findByPath' )->with( 'exam-signup' )->willReturnOnConsecutiveCalls( null, new \WP_Post( array( 'ID' => 7 ) ) );
		$posts->expects( self::once() )->method( 'insert' )->with( array(
			'post_title' => 'Запись', 'post_content' => '[test]', 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'exam-signup',
		) )->willReturn( 7 );
		( new PageGeneratorService( $posts ) )->ensurePublished( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}

	public function test_published_manual_page_is_not_overwritten(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( self::once() )->method( 'findByPath' )->willReturn( new \WP_Post( array( 'ID' => 7, 'post_content' => 'Ручное содержимое' ) ) );
		$posts->expects( self::never() )->method( 'insert' );
		$posts->expects( self::never() )->method( 'update' );
		( new PageGeneratorService( $posts ) )->ensurePublished( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}

	public static function unpublishedPages(): array {
		return array( array( 'draft', 'Ручной текст', 'Ручной текст' ), array( 'pending', '', '[test]' ), array( 'trash', 'Текст', 'Текст' ) );
	}

	#[DataProvider( 'unpublishedPages' )]
	public function test_existing_unpublished_page_keeps_content( string $status, string $content, string $expected ): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( self::exactly( 2 ) )->method( 'findByPath' )->willReturnOnConsecutiveCalls(
			new \WP_Post( array( 'ID' => 7, 'post_status' => $status, 'post_content' => $content ) ), new \WP_Post( array( 'ID' => 7 ) )
		);
		$posts->expects( self::once() )->method( 'update' )->with( 7, array( 'post_status' => 'publish', 'post_content' => $expected ) )->willReturn( true );
		( new PageGeneratorService( $posts ) )->ensurePublished( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}

	public function test_insert_failure_is_not_success(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'findByPath' )->willReturn( null );
		$posts->expects( self::once() )->method( 'insert' )->willReturn( 0 );
		$this->expectException( \RuntimeException::class );
		( new PageGeneratorService( $posts ) )->ensurePublished( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}

	public function test_update_failure_is_not_success(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'findByPath' )->willReturn( new \WP_Post( array( 'ID' => 7, 'post_status' => 'draft' ) ) );
		$posts->expects( self::once() )->method( 'update' )->willReturn( false );
		$this->expectException( \RuntimeException::class );
		( new PageGeneratorService( $posts ) )->ensurePublished( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}

	public function test_wp_filter_that_prevents_publication_is_detected(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->method( 'findByPath' )->willReturn( new \WP_Post( array( 'ID' => 7, 'post_status' => 'draft' ) ) );
		$posts->expects( self::once() )->method( 'update' )->willReturn( true );
		$this->expectException( \RuntimeException::class );
		( new PageGeneratorService( $posts ) )->ensurePublished( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}
	public function test_legacy_create_if_needed_leaves_draft_intact(): void {
		$posts = $this->createMock( PostManager::class );
		$posts->expects( self::once() )->method( 'findByPath' )->willReturn( new \WP_Post( array( 'ID' => 7, 'post_status' => 'draft' ) ) );
		$posts->expects( self::never() )->method( 'insert' );
		$posts->expects( self::never() )->method( 'update' );
		( new PageGeneratorService( $posts ) )->createPageIfNeeded( PageRoutes::ExamSignup, 'Запись', '[test]' );
	}
}
