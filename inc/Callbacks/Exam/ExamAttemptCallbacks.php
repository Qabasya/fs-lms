<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;
use Inc\Enums\Nonce;
use Inc\Enums\Auth\Capability;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamAccessValidator;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

class ExamAttemptCallbacks extends BaseController {

	use Authorizer, AjaxResponse, Sanitizer;

	private ExamAttemptService $attemptService;
	private ExamAccessValidator $accessValidator;
	private AssessmentAttemptRepository $attemptRepo;

	public function __construct(
		?ExamAttemptService $attemptService = null,
		?ExamAccessValidator $accessValidator = null,
		?AssessmentAttemptRepository $attemptRepo = null
	) {
		$this->attemptService = $attemptService ?? new ExamAttemptService();
		$this->accessValidator = $accessValidator ?? new ExamAccessValidator();
		$this->attemptRepo = $attemptRepo ?? new AssessmentAttemptRepository();
	}

	public function ajaxStartExamAttempt(): void {
		$this->authorize( Nonce::StartAttempt, Capability::ManageExams );
		$registrationId = $this->requireInt( 'registration_id' );
		$participationId = $this->requireInt( 'participation_id' );
		$assessmentId = $this->requireInt( 'assessment_id' );
		$sessionId = $this->requireInt( 'session_id' );

		if ( !$this->accessValidator->canStartAttempt( $registrationId, get_current_user_id() ) ) {
			$this->error( 'Доступ запрещён' );
			return;
		}

		try {
			$attemptId = $this->attemptService->createExamAttempt(
				$participationId,
				$assessmentId,
				$registrationId,
				current_time( 'mysql', true ),
				gmdate( 'Y-m-d H:i:s', time() + (4 * 3600) )
			);
			$this->success( [ 'attempt_id' => $attemptId ] );
		} catch ( \Exception $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxSaveExamAnswer(): void {
		$this->authorize( Nonce::SubmitAttempt, Capability::ManageExams );
		$attemptId = $this->requireInt( 'attempt_id' );
		$taskId = $this->requireInt( 'task_id' );
		$answer = $this->requireText( 'answer' );

		$attempt = $this->attemptRepo->find( $attemptId );
		if ( !$attempt || !$attempt->isExam() ) {
			$this->error( 'Попытка не найдена' );
			return;
		}

		$this->success( [ 'saved' => true ] );
	}

	public function ajaxSubmitExamAttempt(): void {
		$this->authorize( Nonce::SubmitAttempt, Capability::ManageExams );
		$attemptId = $this->requireInt( 'attempt_id' );

		$attempt = $this->attemptRepo->find( $attemptId );
		if ( !$attempt || !$attempt->isExam() ) {
			$this->error( 'Попытка не найдена' );
			return;
		}

		$result = $this->attemptService->submitExamAttempt( $attemptId );
		$this->success( [ 'submitted' => $result ] );
	}

	public function ajaxGetExamResult(): void {
		$attemptId = $this->requireInt( 'attempt_id' );

		$attempt = $this->attemptRepo->find( $attemptId );
		if ( !$attempt || !$attempt->isExam() ) {
			$this->error( 'Результат не найден' );
			return;
		}

		$this->success( [
			'attempt_id' => $attempt->id,
			'status' => $attempt->status,
			'total_score' => $attempt->totalScore,
			'max_score' => $attempt->maxScore,
			'approved' => $attempt->isApproved(),
		] );
	}
}
