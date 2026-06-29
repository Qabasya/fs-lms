<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Services\Course\ContentUsageService;
use PHPUnit\Framework\TestCase;

class ContentUsageServiceTest extends TestCase {

	private ContentUsageService $usage;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$this->usage = new ContentUsageService( new PostManager(), new SubjectRepository() );
	}

	public function test_kind_of_detects_bank_types(): void {
		self::assertSame( 'work', ContentUsageService::kindOf( 'inf_works' ) );
		self::assertSame( 'lesson', ContentUsageService::kindOf( 'inf_lessons' ) );
		self::assertSame( 'course', ContentUsageService::kindOf( 'inf_courses' ) );
		self::assertSame( 'article', ContentUsageService::kindOf( 'inf_articles' ) );
		self::assertSame( 'task', ContentUsageService::kindOf( 'inf_tasks' ) );
		self::assertSame( 'problem', ContentUsageService::kindOf( 'fs_lms_problems' ) );
		self::assertSame( '', ContentUsageService::kindOf( 'page' ) );
	}

	public function test_task_used_in_work_counts(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_tasks' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'item_ids' => array( 1 ) ) ) );
		fs_test_seed_post( array( 'ID' => 3, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'item_ids' => array() ) ) );

		self::assertSame( 1, $this->usage->usageCount( 'task', 1 ) );
		self::assertSame( 2, $this->usage->usageList( 'task', 1 )[0]['id'] );
	}

	public function test_work_used_in_lesson_and_lesson_in_course(): void {
		fs_test_seed_post( array( 'ID' => 10, 'post_type' => 'inf_works' ) );
		fs_test_seed_post( array( 'ID' => 11, 'post_type' => 'inf_lessons' ), array( 'fs_lms_meta' => array( 'steps' => array( array( 'key' => 's1', 'type' => 'work', 'payload' => array( 'ref' => 10 ) ) ) ) ) );
		fs_test_seed_post( array( 'ID' => 12, 'post_type' => 'inf_courses' ), array( 'fs_lms_meta' => array( 'modules' => array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 11 ) ) ) ) ) );

		self::assertSame( 1, $this->usage->usageCount( 'work', 10 ) );
		self::assertSame( 1, $this->usage->usageCount( 'lesson', 11 ) );
	}

	public function test_orphan_has_zero_usage(): void {
		fs_test_seed_post( array( 'ID' => 30, 'post_type' => 'inf_tasks' ) );
		fs_test_seed_post( array( 'ID' => 31, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'item_ids' => array( 999 ) ) ) );

		self::assertSame( 0, $this->usage->usageCount( 'task', 30 ) );
	}
}
