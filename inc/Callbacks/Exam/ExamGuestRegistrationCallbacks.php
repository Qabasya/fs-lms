<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;
use Inc\Enums\Nonce;
use Inc\Services\Exam\ExamGuestService;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;

class ExamGuestRegistrationCallbacks extends BaseController {

	use AjaxResponse, Sanitizer;

	private ExamGuestService $guestService;
	private ExamParticipantRepository $participantRepo;
	private ExamGuestApplicationRepository $appRepo;

	public function __construct(
		?ExamGuestService $guestService = null,
		?ExamParticipantRepository $participantRepo = null,
		?ExamGuestApplicationRepository $appRepo = null
	) {
		$this->guestService = $guestService ?? new ExamGuestService();
		$this->participantRepo = $participantRepo ?? new ExamParticipantRepository();
		$this->appRepo = $appRepo ?? new ExamGuestApplicationRepository();
	}

	public function ajaxRegisterGuest(): void {
		check_ajax_referer( Nonce::ExamGuest->value, 'security' );

		$guestName = $this->requireText( 'guest_name' );
		$guestPhone = $this->requireText( 'guest_phone' );
		$guestEmail = $this->requireText( 'guest_email' );
		$guestSchool = $this->sanitizeText( 'guest_school' ) ?: null;
		$guestGrade = $this->sanitizeInt( 'guest_grade' ) ?: null;

		$nameHash = hash( 'sha256', mb_strtolower( $guestName ) );
		$phoneHash = hash( 'sha256', preg_replace( '/[^0-9]/', '', $guestPhone ) );

		$participantId = $this->participantRepo->insert( [
			'name_hash'   => $nameHash,
			'phone_hash'  => $phoneHash,
			'school_name' => $guestSchool,
			'grade'       => $guestGrade,
			'created_at'  => current_time( 'mysql', true ),
			'updated_at'  => current_time( 'mysql', true ),
		] );

		$this->success( [
			'participant_id' => $participantId,
			'message'        => 'Регистрация прошла успешно',
		] );
	}
}
