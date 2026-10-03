<?php

declare( strict_types=1 );

namespace Inc\Controllers\Exam;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Wp\AjaxHook;
use Inc\Core\BaseController;

class ExamAttemptController extends BaseController implements ServiceInterface {

	public function register(): void {
		add_action( 'wp_ajax_' . AjaxHook::StartAttempt->jsAction(), [ $this, 'interceptStartAttempt' ] );
		add_action( 'wp_ajax_' . AjaxHook::SaveAttemptAnswer->jsAction(), [ $this, 'interceptSaveAnswer' ] );
		add_action( 'wp_ajax_' . AjaxHook::SubmitAttempt->jsAction(), [ $this, 'interceptSubmitAttempt' ] );
		add_action( 'wp_ajax_' . AjaxHook::GetAttemptResult->jsAction(), [ $this, 'interceptGetResult' ] );
	}

	public function interceptStartAttempt(): void {
		do_action( 'fs_lms_exam_start_attempt' );
	}

	public function interceptSaveAnswer(): void {
		do_action( 'fs_lms_exam_save_answer' );
	}

	public function interceptSubmitAttempt(): void {
		do_action( 'fs_lms_exam_submit_attempt' );
	}

	public function interceptGetResult(): void {
		do_action( 'fs_lms_exam_get_result' );
	}
}
