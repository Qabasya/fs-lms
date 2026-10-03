<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;
use Inc\Enums\Nonce;
use Inc\Enums\Auth\Capability;
use Inc\Services\Exam\ExamEventService;
use Inc\Services\Exam\ExamSessionService;
use Inc\Services\Exam\ExamParticipationService;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamAttemptService;

class ExamCallbacks extends BaseController {

	use Authorizer, AjaxResponse, Sanitizer;

	private ExamEventService $eventService;
	private ExamSessionService $sessionService;
	private ExamParticipationService $participationService;
	private ExamRegistrationService $registrationService;
	private ExamAttemptService $attemptService;

	public function __construct(
		?ExamEventService $eventService = null,
		?ExamSessionService $sessionService = null,
		?ExamParticipationService $participationService = null,
		?ExamRegistrationService $registrationService = null,
		?ExamAttemptService $attemptService = null
	) {
		$this->eventService = $eventService ?? new ExamEventService();
		$this->sessionService = $sessionService ?? new ExamSessionService();
		$this->participationService = $participationService ?? new ExamParticipationService();
		$this->registrationService = $registrationService ?? new ExamRegistrationService();
		$this->attemptService = $attemptService ?? new ExamAttemptService();
	}

	public function ajaxSaveExamEvent(): void {
		$this->authorize( Nonce::ExamSaveEvent, Capability::ManageExams );
		$eventId = $this->sanitizeInt( 'event_id' ) ?: 0;
		$subjectKey = $this->requireKey( 'subject_key' );
		$title = $this->requireText( 'title' );
		if ( $eventId > 0 ) {
			$this->success( [ 'event_id' => $eventId ] );
		} else {
			$newId = $this->eventService->createEvent( $subjectKey, $title, get_current_user_id() );
			$this->success( [ 'event_id' => $newId ] );
		}
	}

	public function ajaxPublishExamEvent(): void {
		$this->authorize( Nonce::ExamPublishEvent, Capability::ManageExams );
		$eventId = $this->requireInt( 'event_id' );
		$result = $this->eventService->publishEvent( $eventId );
		$this->success( [ 'success' => $result ] );
	}

	public function ajaxSaveExamSession(): void {
		$this->authorize( Nonce::ExamSaveSession, Capability::ManageExams );
		$eventId = $this->requireInt( 'event_id' );
		$newId = $this->sessionService->createSession(
			$eventId,
			$this->requireInt( 'assessment_id' ),
			$this->requireText( 'scheduled_at' ),
			$this->requireText( 'planned_end_at' ),
			$this->requireInt( 'room_id' ),
			$this->requireInt( 'capacity' ),
			get_current_user_id()
		);
		$this->success( [ 'session_id' => $newId ] );
	}

	public function ajaxCreateExamRegistration(): void {
		$this->authorize( Nonce::ExamCreateRegistration, Capability::ManageExams );
		$participationId = $this->requireInt( 'participation_id' );
		$sessionId = $this->requireInt( 'session_id' );
		try {
			$regId = $this->registrationService->registerParticipant( $participationId, $sessionId );
			$this->success( [ 'registration_id' => $regId ] );
		} catch ( \Exception $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxCancelExamRegistration(): void {
		$this->authorize( Nonce::ExamCancelRegistration, Capability::ManageExams );
		$registrationId = $this->requireInt( 'registration_id' );
		$result = $this->registrationService->cancelRegistration( $registrationId );
		$this->success( [ 'success' => $result ] );
	}

	public function ajaxTransferExamRegistration(): void {
		$this->authorize( Nonce::ExamTransferRegistration, Capability::ManageExams );
		$registrationId = $this->requireInt( 'registration_id' );
		$newSessionId = $this->requireInt( 'new_session_id' );
		$result = $this->registrationService->transferRegistration( $registrationId, $newSessionId );
		$this->success( [ 'success' => $result ] );
	}

	public function ajaxGetExamParticipations(): void {
		$this->authorize( Nonce::ExamGetParticipations, Capability::ManageExams );
		$eventId = $this->requireInt( 'event_id' );
		$participations = $this->participationService->getEventParticipations( $eventId );
		$this->success( [ 'participations' => $participations ] );
	}

	public function ajaxGetExamSessions(): void {
		$this->authorize( Nonce::ExamGetSessions, Capability::ManageExams );
		$eventId = $this->requireInt( 'event_id' );
		$sessions = $this->sessionService->getEventSessions( $eventId );
		$this->success( [ 'sessions' => $sessions ] );
	}

	public function ajaxApproveExamAttempt(): void {
		$this->authorize( Nonce::ExamApproveAttempt, Capability::ManageExams );
		$attemptId = $this->requireInt( 'attempt_id' );
		$result = $this->attemptService->approveExamAttempt( $attemptId, get_current_user_id() );
		$this->success( [ 'success' => $result ] );
	}

	public function ajaxCreateExamResultLink(): void {
		$this->authorize( Nonce::ExamCreateResultLink, Capability::ShareExamResults );
		$participationId = $this->requireInt( 'participation_id' );
		$this->success( [ 'link' => site_url( "/exams/result/{$participationId}/" ) ] );
	}

	public function ajaxCreateExamGuestLink(): void {
		$this->authorize( Nonce::ExamCreateGuestLink, Capability::ManageExamGuests );
		$sourceId = $this->requireInt( 'source_id' );
		$this->success( [ 'link' => site_url( "/exams/guest/?source={$sourceId}" ) ] );
	}
}
