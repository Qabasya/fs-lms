<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Assessment;

use Inc\Callbacks\Assessment\GradeAttemptCallbacks;
use Inc\Contracts\ClockInterface;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\AutoGradeService;
use PHPUnit\Framework\TestCase;

class GradeAttemptCallbacksTest extends TestCase {

	private AssessmentAttemptRepository $attempts;
	private GradeAttemptCallbacks       $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->attempts = $this->createMock( AssessmentAttemptRepository::class );
		$this->cb       = new GradeAttemptCallbacks(
			$this->attempts,
			$this->createMock( AssessmentAnswerRepository::class ),
			$this->createMock( AutoGradeService::class ),
			$this->createMock( ClockInterface::class )
		);
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
}
