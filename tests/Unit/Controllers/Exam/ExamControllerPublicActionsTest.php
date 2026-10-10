<?php

declare( strict_types=1 );

namespace Unit\Controllers\Exam;

use Inc\Callbacks\Exam\ExamConductCallbacks;
use Inc\Callbacks\Exam\ExamEventCallbacks;
use Inc\Callbacks\Exam\ExamResultCallbacks;
use Inc\Callbacks\Exam\ExamSourceCallbacks;
use Inc\Callbacks\Exam\ExamStatsCallbacks;
use Inc\Callbacks\Exam\GuestApplicationCallbacks;
use Inc\Callbacks\Exam\LearnerExamCallbacks;
use Inc\Controllers\Exam\ExamController;
use Inc\Enums\Wp\AjaxHook;
use PHPUnit\Framework\TestCase;

/**
 * Гостевая сессия — не вход в WordPress: запись, перенос и отмена записи ученика, а также действия сотрудника публичными не бывают.
 */
class ExamControllerPublicActionsTest extends TestCase {

	public function test_guest_session_cannot_register_change_or_cancel(): void {
		$controller = new ExamController(
			$this->createMock( LearnerExamCallbacks::class ), $this->createMock( ExamEventCallbacks::class ), $this->createMock( ExamSourceCallbacks::class ),
			$this->createMock( ExamConductCallbacks::class ), $this->createMock( ExamResultCallbacks::class ), $this->createMock( ExamStatsCallbacks::class ),
			$this->createMock( GuestApplicationCallbacks::class ), $this->createMock( \Inc\Callbacks\Exam\GuestEntryCallbacks::class ), $this->createMock( \Inc\Callbacks\Exam\ExamReportCallbacks::class ), $this->createMock( \Inc\Callbacks\Exam\ExamPaymentQueueCallbacks::class )
		);
		$method = new \ReflectionMethod( $controller, 'publicAjaxActions' );
		$method->setAccessible( true );
		$public = array_map( static fn ( array $pair ): AjaxHook => $pair[0], $method->invoke( $controller ) );

		self::assertSame( array( AjaxHook::SubmitExamGuestApplication, AjaxHook::CheckExamApplicationStatus, AjaxHook::EndExamGuestSession ), $public );
		foreach ( array( AjaxHook::RegisterForExam, AjaxHook::ChangeExamRegistration, AjaxHook::CancelExamRegistration, AjaxHook::IssueExamEntryLink, AjaxHook::AdmitExamGuest ) as $hook ) {
			self::assertNotContains( $hook, $public );
		}
	}
}
