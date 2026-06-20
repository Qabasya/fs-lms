<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\ContentLifecycleService;
use PHPUnit\Framework\TestCase;

class ContentLifecycleServiceTest extends TestCase {

	private ContentLifecycleService $lifecycle;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$this->lifecycle = new ContentLifecycleService( new PostManager() );
	}

	public function test_archive_sets_archived_status(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_works', 'post_status' => 'publish' ) );

		self::assertTrue( $this->lifecycle->archive( 1 ) );
		self::assertSame( ContentLifecycleService::STATUS_ARCHIVED, get_post( 1 )->post_status );
	}

	public function test_unarchive_restores_publish(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_lessons', 'post_status' => 'fs_archived' ) );

		self::assertTrue( $this->lifecycle->unarchive( 1 ) );
		self::assertSame( 'publish', get_post( 1 )->post_status );
	}

	public function test_non_bank_post_is_rejected(): void {
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'page', 'post_status' => 'publish' ) );

		self::assertFalse( $this->lifecycle->archive( 2 ) );
		self::assertSame( 'publish', get_post( 2 )->post_status );
	}
}
