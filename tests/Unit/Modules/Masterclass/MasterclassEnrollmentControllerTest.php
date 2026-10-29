<?php

declare( strict_types=1 );

namespace Unit\Modules\Masterclass;

use Inc\Modules\Masterclass\Controllers\MasterclassEnrollmentController;
use Inc\Modules\Masterclass\Services\MasterclassConversionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Хуки заявки и зачисления: аккаунт — из сессии, гость ничего не меняет, чужое предзаполнение не затирается, сбои не вылетают.
 */
class MasterclassEnrollmentControllerTest extends TestCase {

	private MasterclassConversionService&MockObject $conversion;
	private MasterclassEnrollmentController $controller;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_fs_test_user_id']  = 105;
		$GLOBALS['_test_logged_in']   = true;

		$this->conversion = $this->createMock( MasterclassConversionService::class );
		$this->controller = new MasterclassEnrollmentController( $this->conversion );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_user_id'], $GLOBALS['_test_logged_in'] );
		parent::tearDown();
	}

	public function test_a_created_application_is_bound_for_the_user_of_the_session(): void {
		$this->conversion->expects( self::once() )->method( 'bindApplication' )->with( 90, 105 );

		$this->controller->onApplicationCreated( 90 );
	}

	public function test_the_response_of_a_bound_application_tells_to_use_the_new_credentials(): void {
		$this->conversion->method( 'bindApplication' )->willReturn( true );

		$this->controller->onApplicationCreated( 90 );
		$response = $this->controller->filterApplyResponse( array( 'join_url' => 'x' ), 90 );

		self::assertSame( 'x', $response['join_url'] );
		self::assertSame( MasterclassEnrollmentController::CREDENTIALS_NOTICE, $response['notice'] );
	}

	public function test_the_notice_is_added_after_a_message_of_another_module(): void {
		$this->conversion->method( 'bindApplication' )->willReturn( true );

		$this->controller->onApplicationCreated( 90 );
		$response = $this->controller->filterApplyResponse( array( 'notice' => 'Учётка создаётся.' ), 90 );

		self::assertSame( 'Учётка создаётся. ' . MasterclassEnrollmentController::CREDENTIALS_NOTICE, $response['notice'] );
	}

	public function test_an_unbound_application_gets_no_notice(): void {
		$this->conversion->method( 'bindApplication' )->willReturn( false );

		$this->controller->onApplicationCreated( 90 );

		self::assertSame( array( 'join_url' => 'x' ), $this->controller->filterApplyResponse( array( 'join_url' => 'x' ), 90 ) );
		self::assertSame( array(), $this->controller->filterApplyResponse( array(), 91 ), 'чужая заявка тоже без подсказки' );
	}

	public function test_a_non_array_response_is_treated_as_empty(): void {
		self::assertSame( array(), $this->controller->filterApplyResponse( 'junk', 90 ) );
	}

	public function test_a_guest_application_is_not_bound(): void {
		$GLOBALS['_fs_test_user_id'] = 0;
		$this->conversion->expects( self::never() )->method( 'bindApplication' );

		$this->controller->onApplicationCreated( 90 );
	}

	public function test_enrollment_marks_the_person(): void {
		$this->conversion->expects( self::once() )->method( 'markEnrolled' )->with( 205 );

		$this->controller->onStudentEnrolled( 31, 205 );
	}

	public function test_a_failing_enrollment_mark_never_breaks_the_enrollment(): void {
		$this->conversion->method( 'markEnrolled' )->willThrowException( new \RuntimeException( 'db down' ) );

		$this->controller->onStudentEnrolled( 31, 205 );

		$this->addToAssertionCount( 1 );
	}

	public function test_prefill_fills_an_empty_form_for_a_logged_in_child(): void {
		$this->conversion->method( 'prefill' )->with( 105 )->willReturn( array( 'subject_key' => 'inf_ege', 'school' => 'Школа №5' ) );

		self::assertSame( array( 'subject_key' => 'inf_ege', 'school' => 'Школа №5' ), $this->controller->filterApplyPrefill( array() ) );
	}

	public function test_prefill_keeps_what_another_subscriber_or_the_guest_link_already_filled(): void {
		$this->conversion->expects( self::never() )->method( 'prefill' );

		self::assertSame( array( 'last_name' => 'Иванов' ), $this->controller->filterApplyPrefill( array( 'last_name' => 'Иванов' ) ) );
	}

	public function test_prefill_is_untouched_for_a_guest(): void {
		$GLOBALS['_test_logged_in'] = false;
		$this->conversion->expects( self::never() )->method( 'prefill' );

		self::assertSame( array(), $this->controller->filterApplyPrefill( array() ) );
	}

	public function test_a_non_array_prefill_is_treated_as_empty(): void {
		$this->conversion->method( 'prefill' )->willReturn( array( 'school' => 'Школа' ) );

		self::assertSame( array( 'school' => 'Школа' ), $this->controller->filterApplyPrefill( 'oops' ) );
	}

	public function test_a_failing_prefill_leaves_the_form_as_it_was(): void {
		$this->conversion->method( 'prefill' )->willThrowException( new \RuntimeException( 'db down' ) );

		self::assertSame( array(), $this->controller->filterApplyPrefill( array() ) );
	}
}
