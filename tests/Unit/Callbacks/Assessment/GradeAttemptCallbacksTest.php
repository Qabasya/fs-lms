<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Assessment;

use Inc\Callbacks\Assessment\GradeAttemptCallbacks;
use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Exam\ExamApprovalService;
use Inc\Services\Exam\ExamConductService;
use PHPUnit\Framework\TestCase;

class GradeAttemptCallbacksTest extends TestCase {

	private AssessmentAttemptRepository $attempts;
	private AssessmentAnswerRepository  $answers;
	private AutoGradeService            $autoGrade;
	private GroupAccessGuard            $guard;
	private PostManager                 $posts;
	private ExamConductService          $examConduct;
	private ExamApprovalService         $examApproval;
	private GradeAttemptCallbacks       $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->attempts  = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers   = $this->createMock( AssessmentAnswerRepository::class );
		$this->autoGrade = $this->createMock( AutoGradeService::class );
		$this->guard     = $this->createMock( GroupAccessGuard::class );
		$this->posts     = $this->createMock( PostManager::class );
		$this->examConduct  = $this->createMock( ExamConductService::class );
		$this->examApproval = $this->createMock( ExamApprovalService::class );
		$this->cb        = new GradeAttemptCallbacks(
			$this->attempts,
			$this->answers,
			$this->autoGrade,
			$this->createMock( ClockInterface::class ),
			$this->guard,
			$this->posts,
			$this->examConduct,
			$this->examApproval,
		);
	}

	private function attemptFixture( int $groupId = 3 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id'                => 5,
			'assessment_id'     => 1,
			'student_person_id' => 9001,
			'group_id'          => $groupId,
			'attempt_number'    => 1,
			'started_at'        => '2026-01-01 00:00:00',
			'deadline_at'       => '2026-01-01 01:00:00',
			'status'            => 'submitted',
		) );
	}

	public function test_grade_attempt_not_found_errors(): void {
		$this->attempts->method( 'find' )->willReturn( null );
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '4', 'is_correct' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_grade_attempt_missing_param_errors(): void {
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_grade_attempt_denied_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->attempts->expects( $this->never() )->method( 'find' );
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_grade_attempt_denied_when_not_manager_of_group(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( false );
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '4', 'is_correct' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	/**
	 * 2026-08-21: ручная оценка запрещена для заданий с авто-чекером — иначе
	 * ручной балл в assessment_answers.score расходится с пересчётом на листе
	 * результатов станции (KegeResultSheetService игнорирует ручной балл, если
	 * у задания есть эталонный ответ).
	 */
	public function test_grade_attempt_denied_for_auto_checkable_task(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'standard_task' ), // авто-проверяемый шаблон
		) );
		$this->answers->expects( $this->never() )->method( 'upsert' );

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '4', 'is_correct' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	/**
	 * Tasks.md, п. 6: явный ЗАЧЁТ автопроверяемого задания разрешён — в отличие
	 * от произвольного балла. Балл = максимум ответа, `graded_by_user_id`
	 * делает его авторитетным и для листа станции.
	 */
	public function test_grade_attempt_credit_allowed_for_auto_checkable_task(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'standard_task' ),
		) );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn(
			new \Inc\DTO\Assessment\AttemptAnswerDTO(
				id: 1, attemptId: 5, taskId: 7, answerText: '41', isCorrect: false,
				score: 0.0, maxScore: 2.0, gradedByUserId: null, gradedAt: null,
			)
		);
		$this->autoGrade->method( 'finalize' )->willReturn( $this->attemptFixture() );

		$this->answers->expects( $this->once() )->method( 'upsert' )->with(
			5, 7,
			self::callback( function ( array $data ) {
				self::assertSame( 2.0, $data['score'] );
				self::assertSame( 2.0, $data['max_score'] );
				self::assertSame( 1, $data['is_correct'] );
				self::assertArrayHasKey( 'graded_by_user_id', $data );
				return true;
			} )
		);

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'credit' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	/** Ответа в БД ещё нет (задание пропущено) — зачёт даёт балл 1 по фолбэку. */
	public function test_grade_attempt_credit_falls_back_to_single_point(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'standard_task' ),
		) );
		$this->answers->method( 'findByAttemptAndTask' )->willReturn( null );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->attemptFixture() );

		$this->answers->expects( $this->once() )->method( 'upsert' )->with(
			5, 7,
			self::callback( function ( array $data ) {
				self::assertSame( 1.0, $data['score'] );
				self::assertSame( 1.0, $data['max_score'] );
				return true;
			} )
		);

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'credit' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	/* ── Критерии (Эпик 13, D17): балл = сумма по критериям, без весов ──────── */

	public function test_grade_attempt_without_criteria_uses_plain_score(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'file_answer_task' ),
			array( 7, 'fs_lms_meta', array() ), // задача без критериев
		) );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->attemptFixture() );

		$this->answers->expects( $this->once() )->method( 'upsert' )->with(
			5, 7,
			self::callback( function ( array $data ) {
				self::assertSame( 4.0, $data['score'] );
				self::assertSame( 1, $data['is_correct'] );
				self::assertArrayNotHasKey( 'criteria_scores', $data );
				return true;
			} )
		);

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '4', 'is_correct' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_grade_attempt_with_criteria_sums_and_clamps_points(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'file_answer_task' ),
			array( 7, 'fs_lms_meta', array(
				'task_criteria' => array( 'criteria' => array(
					array( 'label' => 'К1', 'max_points' => 2 ),
					array( 'label' => 'К2', 'max_points' => 1 ),
				) ),
			) ),
		) );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->attemptFixture() );

		$this->answers->expects( $this->once() )->method( 'upsert' )->with(
			5, 7,
			self::callback( function ( array $data ) {
				// К1 отправлено 5 (> max 2) → клампится до 2; К2 отсутствует → 0.
				self::assertSame( 2.0, $data['score'] );
				self::assertSame( 3.0, $data['max_score'] );
				self::assertSame( 0, $data['is_correct'] ); // 2 < 3, не полный балл
				self::assertSame( '[2,0]', $data['criteria_scores'] );
				return true;
			} )
		);

		// score/is_correct с фронта игнорируются при наличии критериев.
		$_POST = array(
			'attempt_id'      => '5', 'task_id' => '7',
			'score'           => '999', 'is_correct' => '1',
			'criteria_scores' => '{"0":5}',
		);

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_grade_attempt_with_criteria_full_marks_is_correct(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'file_answer_task' ),
			array( 7, 'fs_lms_meta', array(
				'task_criteria' => array( 'criteria' => array(
					array( 'label' => 'К1', 'max_points' => 2 ),
				) ),
			) ),
		) );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->attemptFixture() );

		$this->answers->expects( $this->once() )->method( 'upsert' )->with(
			5, 7,
			self::callback( function ( array $data ) {
				self::assertSame( 1, $data['is_correct'] );
				return true;
			} )
		);

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'criteria_scores' => '{"0":2}' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	/* ── D18: «Утвердить работу» (ЕГЭ без ручной проверки) ───────────────── */

	public function test_approve_attempt_not_found_errors(): void {
		$this->attempts->method( 'find' )->willReturn( null );
		$_POST = array( 'attempt_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxApproveAttempt() )->success );
	}

	public function test_approve_attempt_denied_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false;
		$this->attempts->expects( $this->never() )->method( 'find' );
		$_POST = array( 'attempt_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxApproveAttempt() )->success );
	}

	public function test_approve_attempt_denied_when_not_manager_of_group(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( false );
		$this->attempts->expects( $this->never() )->method( 'approve' );
		$_POST = array( 'attempt_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxApproveAttempt() )->success );
	}

	public function test_approve_attempt_writes_approval(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );

		$this->attempts->expects( $this->once() )->method( 'approve' )->with( 5, self::anything(), self::anything() );

		$_POST = array( 'attempt_id' => '5' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxApproveAttempt() )->success );
	}

	/* ── Этап 8.4: экзаменная попытка ────────────────────────────────────── */

	/** @param array<string, mixed> $override */
	private function examAttempt( array $override = array() ): AttemptDTO {
		return AttemptDTO::fromArray( array_merge( array(
			'id' => 5, 'assessment_id' => 1, 'student_person_id' => 9001, 'attempt_number' => 1,
			'started_at' => '2026-01-01 00:00:00', 'deadline_at' => '2026-01-01 01:00:00', 'status' => 'submitted',
			'exam_participation_id' => 70, 'exam_registration_id' => 20, 'result_version' => 4,
		), $override ) );
	}

	private function manualTask(): void {
		$this->posts->method( 'getMeta' )->willReturnMap( array(
			array( 7, 'fs_lms_template_type', 'file_answer_task' ),
			array( 7, 'fs_lms_meta', array() ),
		) );
	}

	public function test_exam_attempt_grading_requires_event_scope(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt() );
		$this->examConduct->expects( self::once() )->method( 'canManageAttempt' )->willReturn( true );
		$this->guard->expects( self::never() )->method( 'canWriteJournal' );
		$this->attempts->method( 'bumpResultVersion' )->willReturn( true );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->examAttempt() );
		$this->manualTask();
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '2', 'is_correct' => '1', 'result_version' => '4' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_teacher_of_other_subject_cannot_grade_exam_attempt(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt() );
		$this->examConduct->method( 'canManageAttempt' )->willReturn( false );
		$this->attempts->expects( self::never() )->method( 'bumpResultVersion' );
		$this->answers->expects( self::never() )->method( 'upsert' );
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '2', 'is_correct' => '1', 'result_version' => '4' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_attempt_without_group_and_exam_context_is_denied(): void {
		$attempt = AttemptDTO::fromArray( array(
			'id' => 5, 'assessment_id' => 1, 'student_person_id' => 9001, 'attempt_number' => 1,
			'started_at' => '2026-01-01 00:00:00', 'deadline_at' => '2026-01-01 01:00:00', 'status' => 'submitted',
		) );
		$this->attempts->method( 'find' )->willReturn( $attempt );
		$this->guard->expects( self::never() )->method( 'canWriteJournal' );
		$this->answers->expects( self::never() )->method( 'upsert' );
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '2', 'is_correct' => '1' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_grade_with_stale_result_version_is_rejected(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt() );
		$this->examConduct->method( 'canManageAttempt' )->willReturn( true );
		$this->attempts->expects( self::once() )->method( 'bumpResultVersion' )->with( 5, 3 )->willReturn( false );
		$this->answers->expects( self::never() )->method( 'upsert' );
		$this->manualTask();
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '2', 'is_correct' => '1', 'result_version' => '3' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() );

		self::assertFalse( $r->success );
	}

	public function test_approved_exam_attempt_is_not_graded_as_usual(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt( array( 'approved_at' => '2026-01-02 10:00:00' ) ) );
		$this->examConduct->method( 'canManageAttempt' )->willReturn( true );
		$this->attempts->expects( self::never() )->method( 'bumpResultVersion' );
		$this->answers->expects( self::never() )->method( 'upsert' );
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '2', 'is_correct' => '1', 'result_version' => '4' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() )->success );
	}

	public function test_course_attempt_grading_unchanged(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->examConduct->expects( self::never() )->method( 'canManageAttempt' );
		$this->attempts->expects( self::never() )->method( 'bumpResultVersion' );
		$this->autoGrade->method( 'finalize' )->willReturn( $this->attemptFixture() );
		$this->manualTask();
		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'score' => '2', 'is_correct' => '1' );

		$r = fs_test_capture_json( fn() => $this->cb->ajaxGradeAttempt() );

		self::assertTrue( $r->success );
		self::assertArrayNotHasKey( 'result_version', $r->payload );
	}

	public function test_exam_attempt_approval_goes_through_approval_service(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt() );
		$this->examConduct->method( 'canManageAttempt' )->willReturn( true );
		$this->attempts->expects( self::never() )->method( 'approve' );
		$this->examApproval->expects( self::once() )->method( 'approve' )->with( self::anything(), 5, 4 )->willReturn( array( 'status' => 'approved' ) );
		$_POST = array( 'attempt_id' => '5', 'result_version' => '4' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxApproveAttempt() )->success );
	}

	public function test_exam_attempt_approval_refusal_is_reported(): void {
		$this->attempts->method( 'find' )->willReturn( $this->examAttempt() );
		$this->examConduct->method( 'canManageAttempt' )->willReturn( true );
		$this->examApproval->method( 'approve' )->willReturn( array( 'status' => 'skipped', 'reason' => 'pending_review' ) );
		$_POST = array( 'attempt_id' => '5', 'result_version' => '4' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxApproveAttempt() )->success );
	}
}
