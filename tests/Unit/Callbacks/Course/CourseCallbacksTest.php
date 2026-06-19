<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\CourseCallbacks;
use Inc\Services\Course\CourseAuthoringService;
use PHPUnit\Framework\TestCase;

class CourseCallbacksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
	}

	public function test_get_lesson_candidates_delegates_with_input_and_returns(): void {
		$svc = $this->createMock( CourseAuthoringService::class );
		$svc->expects( $this->once() )
			->method( 'getLessonCandidates' )
			->with( 'inf', 'mine', 'поиск' )
			->willReturn( array( array( 'id' => 1, 'title' => 'Урок', 'author' => 0 ) ) );

		$cb    = new CourseCallbacks( $svc );
		$_POST = array( 'subject_key' => 'inf', 'scope' => 'mine', 'search' => 'поиск' );

		$r = fs_test_capture_json( fn() => $cb->ajaxGetCourseLessonCandidates() );

		self::assertTrue( $r->success );
		self::assertSame( array( array( 'id' => 1, 'title' => 'Урок', 'author' => 0 ) ), $r->payload );
	}

	public function test_missing_subject_key_errors_without_calling_service(): void {
		$svc = $this->createMock( CourseAuthoringService::class );
		$svc->expects( $this->never() )->method( 'getLessonCandidates' );

		$cb    = new CourseCallbacks( $svc );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $cb->ajaxGetCourseLessonCandidates() )->success );
	}

	public function test_denies_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false;
		$svc = $this->createMock( CourseAuthoringService::class );
		$svc->expects( $this->never() )->method( 'getLessonCandidates' );

		$cb    = new CourseCallbacks( $svc );
		$_POST = array( 'subject_key' => 'inf' );

		self::assertFalse( fs_test_capture_json( fn() => $cb->ajaxGetCourseLessonCandidates() )->success );
	}
}
