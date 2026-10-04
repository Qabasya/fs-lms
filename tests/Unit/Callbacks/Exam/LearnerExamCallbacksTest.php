<?php

declare( strict_types=1 );

namespace Tests\Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\LearnerExamCallbacks;
use Inc\DTO\Exam\RegistrationResultDTO;
use Inc\DTO\Profile\ProfileContext;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\LearnerExamsService;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * AJAX «Моих экзаменов»: без capability — nonce и владелец данных. Записывается, переносит и отменяет только
 * ученик; родитель читает данные своего ребёнка. Разбор определяется участием, а не идентификатором из запроса.
 */
#[AllowMockObjectsWithoutExpectations]
class LearnerExamCallbacksTest extends TestCase {

	private ProfileViewResolver&MockObject $resolver;
	private LearnerExamsService&MockObject $exams;
	private ExamRegistrationService&MockObject $registration;
	private ExamReviewProjection&MockObject $reviews;
	private LearnerExamCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_test_logged_in'] = true;

		$this->resolver     = $this->createMock( ProfileViewResolver::class );
		$this->exams        = $this->createMock( LearnerExamsService::class );
		$this->registration = $this->createMock( ExamRegistrationService::class );
		$this->reviews      = $this->createMock( ExamReviewProjection::class );

		$this->cb = new LearnerExamCallbacks( $this->resolver, $this->exams, $this->registration, $this->reviews );
	}

	private function asStudent( int $personId = 5 ): void {
		$this->resolver->method( 'context' )->willReturn( new ProfileContext( 1, $personId, UserRole::FSStudent, $personId, false, array() ) );
	}

	private function asParent(): void {
		$this->resolver->method( 'context' )->willReturn(
			new ProfileContext( 1, 3, UserRole::FSParent, 7, true, array( array( 'personId' => 7, 'name' => 'A' ), array( 'personId' => 8, 'name' => 'B' ) ) )
		);
	}

	private function registrationResult(): RegistrationResultDTO {
		return new RegistrationResultDTO( 20, 7, 100, ExamRegistrationStatus::Confirmed, 3 );
	}

	public function test_change_passes_expected_version_to_service(): void {
		$this->asStudent();
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );
		$this->registration->expects( self::once() )->method( 'change' )->with( 5, 100, 'key-1', 12 )->willReturn( $this->registrationResult() );
		$_POST = array( 'session_id' => '100', 'request_key' => 'key-1', 'version' => '12' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxChangeExamRegistration() )->success );
	}

	public function test_cancel_passes_expected_version_to_service(): void {
		$this->asStudent();
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );
		$this->registration->expects( self::once() )->method( 'cancelBySelf' )->with( 5, 1, 'key-2', 4 );
		$_POST = array( 'event_id' => '1', 'request_key' => 'key-2', 'version' => '4' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistration() )->success );
	}

	public function test_missing_version_is_passed_as_null(): void {
		$this->asStudent();
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );
		$this->registration->expects( self::once() )->method( 'cancelBySelf' )->with( 5, 1, 'key-3', null );
		$_POST = array( 'event_id' => '1', 'request_key' => 'key-3' );

		fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistration() );
	}

	public function test_stale_version_is_reported_with_code(): void {
		$this->asStudent();
		$this->registration->method( 'cancelBySelf' )->willThrowException( new CodedException( ErrorCode::ExamStale, 'Запись изменилась в другой вкладке.' ) );
		$_POST = array( 'event_id' => '1', 'request_key' => 'key-4', 'version' => '1' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistration() );

		self::assertFalse( $response->success );
		self::assertSame( 'X-STALE', $response->payload['code'] );
	}

	/** Родитель отклоняется до чтения параметров запроса: сервис записи не зовётся, список карточек не собирается. */
	private function assertParentDenied( string $method, array $post ): void {
		$this->asParent();
		$this->registration->expects( self::never() )->method( 'register' );
		$this->registration->expects( self::never() )->method( 'change' );
		$this->registration->expects( self::never() )->method( 'cancelBySelf' );
		$this->exams->expects( self::never() )->method( 'build' );
		$_POST = $post;

		$response = fs_test_capture_json( fn() => $this->cb->{$method}() );

		self::assertFalse( $response->success );
		self::assertSame( 'X-ACCESS', $response->payload['code'] );
		self::assertSame( 'Записываться и менять запись может только сам ученик.', $response->payload['message'] );
	}

	public function test_parent_cannot_register(): void {
		$this->assertParentDenied( 'ajaxRegisterForExam', array( 'session_id' => '100', 'request_key' => 'k', 'student_person_id' => '7' ) );
	}

	public function test_parent_cannot_change(): void {
		$this->assertParentDenied( 'ajaxChangeExamRegistration', array( 'session_id' => '100', 'request_key' => 'k', 'student_person_id' => '7' ) );
	}

	public function test_parent_cannot_cancel(): void {
		$this->assertParentDenied( 'ajaxCancelExamRegistration', array( 'event_id' => '1', 'request_key' => 'k', 'student_person_id' => '7' ) );
	}

	public function test_guest_user_denied(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->resolver->expects( self::never() )->method( 'context' );
		$this->registration->expects( self::never() )->method( 'register' );
		$this->registration->expects( self::never() )->method( 'change' );
		$this->registration->expects( self::never() )->method( 'cancelBySelf' );

		foreach ( array(
			array( 'ajaxRegisterForExam', array( 'session_id' => '100', 'request_key' => 'k' ) ),
			array( 'ajaxChangeExamRegistration', array( 'session_id' => '100', 'request_key' => 'k' ) ),
			array( 'ajaxCancelExamRegistration', array( 'event_id' => '1', 'request_key' => 'k' ) ),
		) as [ $method, $post ] ) {
			$_POST    = $post;
			$response = fs_test_capture_json( fn() => $this->cb->{$method}() );

			self::assertFalse( $response->success, $method );
		}
	}

	public function test_get_exams_requires_login(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->resolver->expects( self::never() )->method( 'context' );
		$this->exams->expects( self::never() )->method( 'build' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerExams() );

		self::assertFalse( $response->success );
	}

	public function test_student_param_ignored_for_student(): void {
		$this->asStudent( 5 );
		$this->exams->expects( self::once() )->method( 'build' )->with( 5, false )->willReturn( array( 'exams' => array() ) );
		$_POST = array( 'student_person_id' => '999' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerExams() )->success );
	}

	public function test_parent_gets_only_own_child(): void {
		$this->asParent();
		$this->exams->expects( self::exactly( 2 ) )->method( 'build' )->willReturnCallback( function ( int $personId, bool $readOnly ): array {
			self::assertTrue( $readOnly );
			return array( 'exams' => array(), 'person' => $personId );
		} );

		$_POST = array( 'student_person_id' => '8' );
		self::assertSame( 8, fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerExams() )->payload['person'], 'Свой ребёнок выбирается.' );

		$_POST = array( 'student_person_id' => '999' );
		self::assertSame( 7, fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerExams() )->payload['person'], 'Чужой — заменяется ребёнком по умолчанию.' );
	}

	public function test_parent_request_for_foreign_child_returns_own_child(): void {
		$this->asParent();
		$this->exams->expects( self::once() )->method( 'build' )->with( 7, true )->willReturn( array( 'exams' => array() ) );
		$_POST = array( 'student_person_id' => '999' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerExams() );

		self::assertTrue( $response->success, 'Не ошибка и не чужие данные: подставляется собственный ребёнок.' );
	}

	// ---- запись, перенос, отмена: делегирование и коды ----------------------------------------------------------------------------

	public function test_register_delegates_with_person_of_current_user(): void {
		$this->asStudent( 5 );
		$this->registration->expects( self::once() )->method( 'register' )->with( 5, 100, 'key-reg' )->willReturn( $this->registrationResult() );
		$this->exams->expects( self::once() )->method( 'build' )->with( 5, false )->willReturn( array( 'exams' => array( array( 'event_id' => 1 ) ) ) );
		$_POST = array( 'session_id' => '100', 'request_key' => 'key-reg' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxRegisterForExam() );

		self::assertTrue( $response->success );
		self::assertSame( array( array( 'event_id' => 1 ) ), $response->payload['exams'], 'Ответ — заново собранные карточки.' );
	}

	public function test_register_passes_lesson_overlap_warning_to_the_client(): void {
		$this->asStudent( 5 );
		$this->registration->method( 'register' )->willReturn( new RegistrationResultDTO( 20, 7, 100, ExamRegistrationStatus::Confirmed, 3, false, array( 'lesson_overlap' ) ) );
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );
		$_POST = array( 'session_id' => '100', 'request_key' => 'key-warn' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxRegisterForExam() );

		self::assertTrue( $response->success, 'Запись создана — предупреждение не отказ.' );
		self::assertSame( array( 'lesson_overlap' ), $response->payload['warnings'] );
	}

	public function test_cancel_response_has_empty_warnings(): void {
		$this->asStudent( 5 );
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );
		$_POST = array( 'event_id' => '1', 'request_key' => 'key-x' );

		self::assertSame( array(), fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistration() )->payload['warnings'] );
	}

	public function test_register_ignores_client_person_id(): void {
		$this->asStudent( 5 );
		$this->registration->expects( self::once() )->method( 'register' )->with( 5, 100, 'key-reg' )->willReturn( $this->registrationResult() );
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );
		$_POST = array( 'session_id' => '100', 'request_key' => 'key-reg', 'student_person_id' => '999', 'person_id' => '999' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxRegisterForExam() )->success );
	}

	public function test_register_returns_x_full_code(): void {
		$this->asStudent( 5 );
		$this->registration->method( 'register' )->willThrowException( new CodedException( ErrorCode::ExamFull, 'Свободных мест нет.' ) );
		$this->exams->expects( self::never() )->method( 'build' );
		$_POST = array( 'session_id' => '100', 'request_key' => 'key-full' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxRegisterForExam() );

		self::assertFalse( $response->success );
		self::assertSame( 'X-FULL', $response->payload['code'] );
		self::assertSame( 'Свободных мест нет.', $response->payload['message'] );
	}

	public function test_change_and_cancel_delegate(): void {
		$this->asStudent( 5 );
		$this->registration->expects( self::once() )->method( 'change' )->with( 5, 101, 'key-c', null )->willReturn( $this->registrationResult() );
		$this->registration->expects( self::once() )->method( 'cancelBySelf' )->with( 5, 1, 'key-x', null );
		$this->exams->method( 'build' )->willReturn( array( 'exams' => array() ) );

		$_POST = array( 'session_id' => '101', 'request_key' => 'key-c' );
		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxChangeExamRegistration() )->success );

		$_POST = array( 'event_id' => '1', 'request_key' => 'key-x' );
		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxCancelExamRegistration() )->success );
	}

	public function test_missing_parameters_are_refused_before_the_service_is_called(): void {
		$this->asStudent( 5 );
		$this->registration->expects( self::never() )->method( 'register' );
		$this->registration->expects( self::never() )->method( 'change' );
		$this->registration->expects( self::never() )->method( 'cancelBySelf' );

		foreach ( array(
			array( 'ajaxRegisterForExam', array( 'session_id' => '100' ) ),
			array( 'ajaxRegisterForExam', array( 'request_key' => 'k' ) ),
			array( 'ajaxChangeExamRegistration', array( 'session_id' => '0', 'request_key' => 'k' ) ),
			array( 'ajaxCancelExamRegistration', array( 'event_id' => '1' ) ),
		) as [ $method, $post ] ) {
			$_POST = $post;

			self::assertFalse( fs_test_capture_json( fn() => $this->cb->{$method}() )->success, $method );
		}
	}

	public function test_unexpected_failure_does_not_leak_details_to_the_client(): void {
		$this->asStudent( 5 );
		$this->registration->method( 'register' )->willThrowException( new \RuntimeException( "SQL: Duplicate entry 'x' for key 'participation_active'" ) );
		$_POST = array( 'session_id' => '100', 'request_key' => 'key-boom' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxRegisterForExam() );

		self::assertFalse( $response->success );
		self::assertStringNotContainsString( 'SQL', (string) json_encode( $response->payload, JSON_UNESCAPED_UNICODE ) );
	}
	public function test_review_ignores_attempt_id_param(): void {
		$this->asStudent();
		$this->reviews->expects( self::once() )->method( 'forStudent' )->with( 5, 1 )->willReturn( array( 'revealed' => false, 'status' => 'submitted' ) );
		$_POST = array( 'event_id' => '1', 'attempt_id' => '999' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamReview() )->success );
	}

	public function test_review_denied_for_foreign_event_without_details(): void {
		$this->asStudent();
		$this->reviews->method( 'forStudent' )->willReturn( null );
		$_POST = array( 'event_id' => '77' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxGetExamReview() );

		self::assertFalse( $response->success );
		self::assertSame( 'X-ACCESS', $response->payload['code'] );
		self::assertSame( 'Результат недоступен.', $response->payload['message'] );
	}

	public function test_review_before_approval_returns_unrevealed_without_tasks(): void {
		$this->asStudent();
		$this->reviews->method( 'forStudent' )->willReturn( array( 'revealed' => false, 'status' => 'submitted' ) );
		$_POST = array( 'event_id' => '1' );

		$response = fs_test_capture_json( fn() => $this->cb->ajaxGetExamReview() );

		self::assertTrue( $response->success );
		self::assertFalse( $response->payload['revealed'] );
		self::assertArrayNotHasKey( 'tasks', $response->payload );
	}

	public function test_parent_gets_review_of_own_child_only(): void {
		$this->asParent();
		// Чужого ребёнка (999) родитель не получает: подставляется ребёнок по умолчанию (7).
		$this->reviews->expects( self::once() )->method( 'forStudent' )->with( 7, 1 )->willReturn( array( 'revealed' => false, 'status' => 'submitted' ) );
		$_POST = array( 'event_id' => '1', 'student_person_id' => '999' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetExamReview() )->success );
	}

	public function test_exam_list_for_parent_is_read_only(): void {
		$this->asParent();
		$this->exams->expects( self::once() )->method( 'build' )->with( 8, true )->willReturn( array( 'exams' => array() ) );
		$_POST = array( 'student_person_id' => '8' );

		self::assertTrue( fs_test_capture_json( fn() => $this->cb->ajaxGetLearnerExams() )->success );
	}
}
