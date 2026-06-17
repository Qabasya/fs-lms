<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\PostManager;
use Inc\Services\Course\LessonAuthoringService;
use PHPUnit\Framework\TestCase;

class LessonAuthoringServiceTest extends TestCase {

	private LessonAuthoringService $service;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$this->service = new LessonAuthoringService( new PostManager() );
	}

	public function test_work_candidates_expose_work_type_from_meta(): void {
		fs_test_seed_post(
			array( 'ID' => 1, 'post_type' => 'inf_works', 'post_title' => 'ДЗ' ),
			array( 'fs_lms_meta' => array( 'work_type' => 'homework' ) )
		);

		$candidates = $this->service->getWorkCandidates( 'inf', '', 'subject' );

		self::assertCount( 1, $candidates );
		self::assertSame( 'homework', $candidates[0]['work_type'] );
	}

	public function test_work_candidates_filter_by_type(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'work_type' => 'homework' ) ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_works' ), array( 'fs_lms_meta' => array( 'work_type' => 'practice' ) ) );

		$ids = array_column( $this->service->getWorkCandidates( 'inf', 'practice', 'subject' ), 'id' );

		self::assertSame( array( 2 ), $ids );
	}

	public function test_validate_work_ids_drops_foreign(): void {
		fs_test_seed_post( array( 'ID' => 1, 'post_type' => 'inf_works' ) );
		fs_test_seed_post( array( 'ID' => 2, 'post_type' => 'inf_lessons' ) );

		self::assertSame( array( 1 ), $this->service->validateWorkIds( 'inf', array( 1, 2 ) ) );
	}
}
