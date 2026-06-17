<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\PostManager;
use Inc\Services\Course\CourseAuthoringService;
use PHPUnit\Framework\TestCase;

class CourseAuthoringServiceTest extends TestCase {

	private CourseAuthoringService $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$this->service = new CourseAuthoringService( new PostManager() );
	}

	public function test_lesson_candidates_are_scoped_to_subject(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_lessons', 'post_title' => 'Урок 1' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'rus_lessons', 'post_title' => 'Чужой' ) );

		$ids = array_column( $this->service->getLessonCandidates( 'inf', 'subject' ), 'id' );

		self::assertSame( array( 1 ), $ids );
	}

	public function test_validate_lesson_ids_drops_foreign(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_lessons' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_courses' ) );

		self::assertSame( array( 1 ), $this->service->validateLessonIds( 'inf', array( 1, 2 ) ) );
	}
}
