<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\GradingCallbacks;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\WorkDetailService;
use Inc\Services\Course\WorkResetService;
use Inc\Services\Course\SubmissionService;
use PHPUnit\Framework\TestCase;

class GradingCallbacksTest extends TestCase {

	private SubmissionService     $service;
	private GroupAccessGuard      $guard;
	private SubmissionRepository  $submissions;
	private GroupLessonRepository $groupLessons;
	private WorkDetailService     $workDetail;
	private WorkResetService      $workReset;
	private GradingCallbacks      $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service      = $this->createMock( SubmissionService::class );
		$this->guard        = $this->createMock( GroupAccessGuard::class );
		$this->submissions  = $this->createMock( SubmissionRepository::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->workDetail   = $this->createMock( WorkDetailService::class );
		$this->workReset    = $this->createMock( WorkResetService::class );
		$this->cb           = new GradingCallbacks(
			$this->service, $this->guard, $this->submissions, $this->groupLessons, $this->workDetail, $this->workReset
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

	public function test_get_work_detail_returns_detail(): void {
		$this->workDetail->expects( $this->once() )
			->method( 'forWork' )
			->with( 'submission', 5 )
			->willReturn( array( 'kind' => 'work', 'title' => 'Работа', 'tasks' => array(), 'group_id' => 1 ) );
		$this->guard->method( 'canManage' )->willReturn( true );
		$_POST = array( 'source_type' => 'submission', 'source_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDetail() );

		self::assertTrue( $r->success );
		self::assertSame( 'work', $r->payload['kind'] );
		self::assertArrayNotHasKey( 'group_id', $r->payload ); // не утекает клиенту
	}

	public function test_get_work_detail_not_found_errors(): void {
		$this->workDetail->method( 'forWork' )->willReturn( null );
		$_POST = array( 'source_type' => 'submission', 'source_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDetail() )->success );
	}

	public function test_get_work_detail_denied_when_not_manager(): void {
		$this->workDetail->method( 'forWork' )->willReturn( array( 'kind' => 'work', 'group_id' => 9 ) );
		$this->guard->method( 'canManage' )->willReturn( false );
		$_POST = array( 'source_type' => 'submission', 'source_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkDetail() )->success );
	}

	/* ── getWorkAttemptHistory(): «Пройти заново» — история попыток педагогу ── */

	private function submissionFixture(): SubmissionDTO {
		return new SubmissionDTO(
			id: 5, studentPersonId: 10, groupLessonId: 20, workId: 3, workType: WorkType::Practice,
			taskId: null, answerText: null, attachmentId: null, dueAt: null,
			status: SubmissionStatus::Submitted, score: null, maxScore: null, feedback: null,
			gradedByUserId: null, submittedAt: '2026-08-26 19:15:00', gradedAt: null,
			createdAt: '', updatedAt: '',
		);
	}

	private function groupLessonFixture( int $groupId ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 20, groupId: $groupId, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: '2026-08-20 09:00:00', endsAt: null, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null,
		);
	}

	public function test_get_work_attempt_history_submission_not_found_errors(): void {
		$this->submissions->method( 'find' )->willReturn( null );
		$this->workDetail->expects( $this->never() )->method( 'attemptHistory' );
		$_POST = array( 'submission_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkAttemptHistory() )->success );
	}

	public function test_get_work_attempt_history_denied_when_not_manager(): void {
		$this->submissions->method( 'find' )->willReturn( $this->submissionFixture() );
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLessonFixture( 9 ) );
		$this->guard->method( 'canManage' )->willReturn( false );
		$this->workDetail->expects( $this->never() )->method( 'attemptHistory' );
		$_POST = array( 'submission_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetWorkAttemptHistory() )->success );
	}

	public function test_get_work_attempt_history_returns_attempts(): void {
		$this->submissions->method( 'find' )->willReturn( $this->submissionFixture() );
		$this->groupLessons->method( 'find' )->willReturn( $this->groupLessonFixture( 9 ) );
		$this->guard->method( 'canManage' )->willReturn( true );
		$this->workDetail->expects( $this->once() )
			->method( 'attemptHistory' )
			->with( 5 )
			->willReturn( array( array( 'round' => 1, 'is_current' => true, 'tasks' => array() ) ) );
		$_POST = array( 'submission_id' => '5' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGetWorkAttemptHistory() );

		self::assertTrue( $r->success );
		self::assertCount( 1, $r->payload['attempts'] );
	}
}
