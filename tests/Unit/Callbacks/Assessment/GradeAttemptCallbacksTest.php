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
use PHPUnit\Framework\TestCase;

class GradeAttemptCallbacksTest extends TestCase {

	private AssessmentAttemptRepository $attempts;
	private AssessmentAnswerRepository  $answers;
	private AutoGradeService            $autoGrade;
	private GroupAccessGuard            $guard;
	private PostManager                 $posts;
	private GradeAttemptCallbacks       $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->attempts  = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers   = $this->createMock( AssessmentAnswerRepository::class );
		$this->autoGrade = $this->createMock( AutoGradeService::class );
		$this->guard     = $this->createMock( GroupAccessGuard::class );
		$this->posts     = $this->createMock( PostManager::class );
		$this->cb        = new GradeAttemptCallbacks(
			$this->attempts,
			$this->answers,
			$this->autoGrade,
			$this->createMock( ClockInterface::class ),
			$this->guard,
			$this->posts,
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

	/* ── Критерии (Эпик 13, D17): балл = сумма по критериям, без весов ──────── */

	public function test_grade_attempt_without_criteria_uses_plain_score(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attemptFixture() );
		$this->guard->method( 'canWriteJournal' )->willReturn( true );
		$this->posts->method( 'getMeta' )->willReturn( array() ); // задача без критериев
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
		$this->posts->method( 'getMeta' )->willReturn( array(
			'task_criteria' => array( 'criteria' => array(
				array( 'label' => 'К1', 'max_points' => 2 ),
				array( 'label' => 'К2', 'max_points' => 1 ),
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
		$this->posts->method( 'getMeta' )->willReturn( array(
			'task_criteria' => array( 'criteria' => array(
				array( 'label' => 'К1', 'max_points' => 2 ),
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
}
