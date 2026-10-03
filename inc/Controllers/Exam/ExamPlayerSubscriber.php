<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Callbacks\Exam\ExamAttemptCallbacks;

class ExamPlayerSubscriber implements ServiceInterface {

	private ExamAttemptCallbacks $callbacks;

	public function __construct( ?ExamAttemptCallbacks $callbacks = null ) {
		$this->callbacks = $callbacks ?? new ExamAttemptCallbacks();
	}

	public function register(): void {
		add_action( 'fs_lms_exam_start_attempt', [ $this->callbacks, 'ajaxStartExamAttempt' ] );
		add_action( 'fs_lms_exam_save_answer', [ $this->callbacks, 'ajaxSaveExamAnswer' ] );
		add_action( 'fs_lms_exam_submit_attempt', [ $this->callbacks, 'ajaxSubmitExamAttempt' ] );
		add_action( 'fs_lms_exam_get_result', [ $this->callbacks, 'ajaxGetExamResult' ] );

		add_filter( 'fs_lms_attempt_duration_extended', [ $this, 'allowExamExtensions' ], 10, 2 );
	}

	public function allowExamExtensions( bool $allowed, int $attemptId ): bool {
		return $allowed;
	}
}
