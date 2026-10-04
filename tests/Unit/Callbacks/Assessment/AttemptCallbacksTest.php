<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Assessment;

use Inc\Callbacks\Assessment\AttemptCallbacks;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\AttemptContext;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Log\ErrorCode;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Assessment\AssessmentAccessPolicy;
use Inc\Services\Assessment\AttemptResultService;
use Inc\Services\Assessment\AttemptService;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Shared\CodedException;
use PHPUnit\Framework\TestCase;

class AttemptCallbacksTest extends TestCase {

	private AttemptService       $service;
	private PersonRepository     $persons;
	private AttemptResultService   $resultService;
	private AssessmentManager      $assessments;
	private AssessmentAccessPolicy $access;
	private ExamAttemptService     $examAttempts;
	private AttemptCallbacks       $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$this->service       = $this->createMock( AttemptService::class );
		$this->persons       = $this->createMock( PersonRepository::class );
		$this->resultService = $this->createMock( AttemptResultService::class );
		$this->examAttempts  = $this->createMock( ExamAttemptService::class );
		$this->cb            = new AttemptCallbacks(
			$this->service,
			$this->persons,
			$this->resultService,
			$this->createMock( AssessmentManager::class ),
			$this->createMock( AssessmentAccessPolicy::class ),
			$this->examAttempts,
		);
	}

	private function person( int $id ): PersonDTO {
		return PersonDTO::fromArray( array( 'id' => $id, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00' ) );
	}

	private function submittedAttempt( int $id = 7, int $studentPersonId = 99 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id'                => $id,
			'assessment_id'     => 1,
			'student_person_id' => $studentPersonId,
			'group_id'          => null,
			'attempt_number'    => 1,
			'started_at'        => '2026-06-01 10:00:00',
			'deadline_at'       => '2026-06-01 11:00:00',
			'status'            => 'submitted',
			'total_score'       => '8',
			'max_score'         => '10',
		) );
	}

	public function test_save_answer_passes_answer_text_through(): void {
		// Регрессия: answer_text должен дойти до сервиса (был тот же баг sanitizeEditorContent).
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->expects( $this->once() )
			->method( 'saveAnswer' )
			->with( 5, 7, 'Мой ответ', 99 );

		$_POST = array( 'attempt_id' => '5', 'task_id' => '7', 'answer_text' => 'Мой ответ' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttemptAnswer() )->success );
	}

	public function test_save_answer_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'saveAnswer' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttemptAnswer() )->success );
	}

	public function test_start_attempt_profile_not_found_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'start' );
		$_POST = array( 'assessment_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() )->success );
	}

	public function test_start_attempt_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'start' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() )->success );
	}

	public function test_submit_attempt_includes_per_task_from_result_service(): void {
		// T13.7: ответ submit_attempt должен нести per_task из AttemptResultService.
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->method( 'submit' )->with( 7, 99 )->willReturn( $this->submittedAttempt() );
		$this->service->method( 'isRevealed' )->willReturn( true );
		$perTask = array( array( 'n' => 1, 'task_id' => 10, 'verdict' => 'correct' ) );
		$this->resultService->method( 'studentPerTask' )->with( 7, 99 )->willReturn( $perTask );

		$_POST = array( 'attempt_id' => '7' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxSubmitAttempt() );

		self::assertTrue( $response->success );
		self::assertSame( 'submitted', $response->payload['status'] );
		self::assertSame( 8.0, $response->payload['total_score'] );
		self::assertSame( 10.0, $response->payload['max_score'] );
		self::assertSame( $perTask, $response->payload['per_task'] );
	}

	public function test_submit_attempt_profile_not_found_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'submit' );
		$_POST = array( 'attempt_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitAttempt() )->success );
	}

	public function test_submit_attempt_missing_param_errors(): void {
		$this->service->expects( $this->never() )->method( 'submit' );
		$_POST = array();

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxSubmitAttempt() )->success );
	}

	public function test_submit_attempt_service_exception_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->method( 'submit' )->willThrowException( new \RuntimeException( 'Попытка уже завершена.' ) );
		$this->resultService->expects( $this->never() )->method( 'studentPerTask' );

		$_POST = array( 'attempt_id' => '7' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxSubmitAttempt() );

		self::assertFalse( $response->success );
	}

	public function test_get_attempt_result_success(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->method( 'getResult' )->with( 7, 99 )->willReturn( array( 'status' => 'submitted' ) );
		$_POST = array( 'attempt_id' => '7' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxGetAttemptResult() );

		self::assertTrue( $response->success );
		self::assertSame( 'submitted', $response->payload['status'] );
	}

	public function test_get_attempt_result_profile_not_found_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( null );
		$this->service->expects( $this->never() )->method( 'getResult' );
		$_POST = array( 'attempt_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetAttemptResult() )->success );
	}

	public function test_get_attempt_result_invalid_argument_errors(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->method( 'getResult' )->willThrowException( new \InvalidArgumentException( 'Попытка не найдена.' ) );
		$_POST = array( 'attempt_id' => '7' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetAttemptResult() )->success );
	}

	private function examAttempt( int $id = 7 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id'                    => $id,
			'assessment_id'         => 1,
			'student_person_id'     => 99,
			'attempt_number'        => 1,
			'started_at'            => '2026-06-01 10:00:00',
			'deadline_at'           => '2026-06-01 11:00:00',
			'status'                => 'submitted',
			'total_score'           => '8',
			'max_score'             => '10',
			'exam_participation_id' => 5,
			'exam_registration_id'  => 6,
		) );
	}

	private function examContext(): AttemptContext {
		return new AttemptContext( ExamAudience::Student, 5, 6, 99, 42 );
	}

	public function test_start_with_exam_registration_uses_exam_service(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->examAttempts->expects( $this->once() )->method( 'contextForStudent' )->with( 0, 6 )->willReturn( $this->examContext() );
		$this->examAttempts->expects( $this->once() )->method( 'start' )->willReturn( $this->examAttempt() );
		$this->service->expects( $this->never() )->method( 'start' );

		$_POST = array( 'assessment_id' => '1', 'exam_registration_id' => '6' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() )->success );
	}

	public function test_start_without_exam_param_uses_legacy_path(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->expects( $this->once() )->method( 'start' )->with( 99, 1, null, null )->willReturn( $this->examAttempt() );
		$this->examAttempts->expects( $this->never() )->method( 'start' );

		$_POST = array( 'assessment_id' => '1' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() )->success );
	}

	public function test_start_exam_refusal_returns_code(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->examAttempts->method( 'contextForStudent' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'start' )->willThrowException( new CodedException( ErrorCode::ExamNotOpen, 'Экзамен ещё не начался.' ) );

		$_POST = array( 'assessment_id' => '1', 'exam_registration_id' => '6' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxStartAttempt() );

		self::assertFalse( $response->success );
		self::assertSame( 'X-NOT-OPEN', $response->payload['code'] );
	}

	public function test_save_of_exam_attempt_goes_only_through_exam_service(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->examAttempts->method( 'contextForAttempt' )->willReturn( $this->examContext() );
		$this->examAttempts->expects( $this->once() )->method( 'saveAnswer' )->with( $this->examContext(), 7, 3, 'ответ' );
		$this->service->expects( $this->never() )->method( 'saveAnswer' );

		$_POST = array( 'attempt_id' => '7', 'task_id' => '3', 'answer_text' => 'ответ' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxSaveAttemptAnswer() )->success );
	}

	public function test_save_of_foreign_exam_attempt_is_denied_with_code(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->examAttempts->method( 'contextForAttempt' )->willThrowException( new CodedException( ErrorCode::ExamAccess, 'Экзамен недоступен.' ) );
		$this->examAttempts->expects( $this->never() )->method( 'saveAnswer' );
		$this->service->expects( $this->never() )->method( 'saveAnswer' );

		$_POST = array( 'attempt_id' => '7', 'task_id' => '3', 'answer_text' => 'x' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxSaveAttemptAnswer() );

		self::assertFalse( $response->success );
		self::assertSame( 'X-ACCESS', $response->payload['code'] );
	}

	public function test_submit_of_exam_attempt_returns_no_scores(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->examAttempts->method( 'contextForAttempt' )->willReturn( $this->examContext() );
		$this->examAttempts->method( 'submit' )->willReturn( $this->examAttempt() );
		$this->service->method( 'isRevealed' )->willReturn( false );
		$this->service->expects( $this->never() )->method( 'submit' );
		$this->resultService->expects( $this->never() )->method( 'studentPerTask' );

		$_POST = array( 'attempt_id' => '7' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxSubmitAttempt() );

		self::assertTrue( $response->success );
		self::assertSame( array( 'status' => 'submitted' ), $response->payload );
	}

	public function test_submit_of_unrevealed_course_attempt_has_no_scores(): void {
		// Одна политика раскрытия на все ответы: станция ЕГЭ в курсе до утверждения тоже не получает итог.
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->service->method( 'submit' )->willReturn( $this->submittedAttempt() );
		$this->service->method( 'isRevealed' )->willReturn( false );
		$this->resultService->expects( $this->never() )->method( 'studentPerTask' );

		$_POST = array( 'attempt_id' => '7' );

		self::assertSame( array( 'status' => 'submitted' ), fs_test_capture_json( fn() => $this->cb->ajaxSubmitAttempt() )->payload );
	}

	public function test_get_result_of_exam_attempt_goes_through_exam_service(): void {
		$this->persons->method( 'findByWpUserId' )->willReturn( $this->person( 99 ) );
		$this->examAttempts->method( 'contextForAttempt' )->willReturn( $this->examContext() );
		$this->examAttempts->expects( $this->once() )->method( 'result' )->with( $this->examContext(), 7 )->willReturn( array( 'status' => 'submitted' ) );
		$this->service->expects( $this->never() )->method( 'getResult' );

		$_POST = array( 'attempt_id' => '7' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetAttemptResult() )->success );
	}
}
