<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\GradingCallbacks;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

class GradingCallbacksTest extends TestCase {

	private SubmissionService     $service;
	private GradebookService      $gradebook;
	private GroupAccessGuard      $guard;
	private SubmissionRepository  $submissions;
	private GroupLessonRepository $groupLessons;
	private GradingCallbacks      $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service      = $this->createMock( SubmissionService::class );
		$this->gradebook    = $this->createMock( GradebookService::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->cb           = new GradingCallbacks(
			$this->service, $this->gradebook, $this->guard, $this->submissions, $this->groupLessons
		);
	}

	public function test_save_grade_submission_not_found_errors(): void {
		$this->submissions->method( 'find' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'grade' );
		$_POST = array( 'submission_id' => '5', 'score' => '4', 'max_score' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveGrade() )->success );
	}

	public function test_save_grade_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'grade' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveGrade() )->success );
	}

	public function test_get_group_submissions_returns_queue(): void {
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->submissions->method( 'listQueueByGroup' )->willReturn( array() );
		$_POST = array( 'group_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetGroupSubmissions() );

		self::assertTrue( $r->success );
		self::assertSame( array(), $r->payload );
	}

	public function test_get_gradebook_without_group_returns_empty(): void {
		$_POST = array();

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetGradebook() );

		self::assertTrue( $r->success );
		self::assertSame( array(), $r->payload );
	}

	public function test_get_gradebook_denied_for_foreign_group(): void {
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->gradebook->expects( $this->never() )->method( 'forGroup' );
		$_POST = array( 'group_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetGradebook() )->success );
	}
}
