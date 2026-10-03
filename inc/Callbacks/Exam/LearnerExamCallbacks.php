<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;
use Inc\Enums\Nonce;
use Inc\Enums\Log\ErrorCode;
use Inc\Services\Profile\ProfileViewResolver;
use Inc\Services\Exam\LearnerExamsService;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Shared\CodedException;

class LearnerExamCallbacks extends BaseController {

	use AjaxResponse, Sanitizer;

	private ?ExamRegistrationService $registrationService = null;

	public function __construct(
		private ?ProfileViewResolver $resolver = null,
		private ?LearnerExamsService $examsService = null,
		?ExamRegistrationService $registrationService = null
	) {
		$this->resolver ??= new ProfileViewResolver();
		$this->examsService ??= new LearnerExamsService();
		$this->registrationService = $registrationService ?? new ExamRegistrationService();
	}

	public function ajaxGetLearnerExams(): void {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет' );
			return;
		}

		$ctx = $this->resolver->context( get_current_user_id() );
		$personId = $this->resolveSubjectPersonId( $this->sanitizeInt( 'student_person_id' ) );

		// Если personId null или не входит в доступные ученику (родитель → только свой ребёнок),
		// выход с ошибкой
		if ( null === $personId ) {
			$this->error( 'Не удалось определить ученика' );
			return;
		}

		$readOnly = $ctx->readOnly;
		$result = $this->examsService->build( $personId, $readOnly );
		$this->success( $result );
	}

	public function ajaxRegisterForExam(): void {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет' );
			return;
		}

		$ctx = $this->resolver->context( get_current_user_id() );

		// Родитель не может записываться
		if ( $ctx->readOnly ) {
			$this->fail( ErrorCode::ExamAccess, 'Запись доступна только самому ученику.' );
			return;
		}

		$sessionId = $this->sanitizeInt( 'session_id' );
		$requestKey = $this->sanitizeKey( 'request_key' );

		if ( ! $sessionId || ! $requestKey ) {
			$this->error( 'Недостаточно данных для регистрации' );
			return;
		}

		try {
			$this->registrationService->registerParticipant( $ctx->personId, $sessionId, $requestKey );
			// Собрать обновлённую карточку
			$personId = $ctx->personId;
			$exams = $this->examsService->build( $personId, false );
			$this->success( $exams );
		} catch ( CodedException $e ) {
			$this->fail( $e->code(), $e->getMessage() );
		} catch ( \Exception $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxChangeExamRegistration(): void {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет' );
			return;
		}

		$ctx = $this->resolver->context( get_current_user_id() );

		// Родитель не может менять запись
		if ( $ctx->readOnly ) {
			$this->fail( ErrorCode::ExamAccess, 'Запись доступна только самому ученику.' );
			return;
		}

		$newSessionId = $this->sanitizeInt( 'session_id' );
		$requestKey = $this->sanitizeKey( 'request_key' );

		if ( ! $newSessionId || ! $requestKey ) {
			$this->error( 'Недостаточно данных' );
			return;
		}

		try {
			$this->registrationService->transferRegistration( $ctx->personId, $newSessionId, $requestKey );
			// Собрать обновлённую карточку
			$personId = $ctx->personId;
			$exams = $this->examsService->build( $personId, false );
			$this->success( $exams );
		} catch ( CodedException $e ) {
			$this->fail( $e->code(), $e->getMessage() );
		} catch ( \Exception $e ) {
			$this->error( $e->getMessage() );
		}
	}

	public function ajaxCancelExamRegistration(): void {
		Nonce::ExamLearner->verify();

		if ( ! is_user_logged_in() ) {
			$this->error( 'Требуется вход в кабинет' );
			return;
		}

		$ctx = $this->resolver->context( get_current_user_id() );

		// Родитель не может отменять запись
		if ( $ctx->readOnly ) {
			$this->fail( ErrorCode::ExamAccess, 'Запись доступна только самому ученику.' );
			return;
		}

		$eventId = $this->sanitizeInt( 'event_id' );
		$requestKey = $this->sanitizeKey( 'request_key' );

		if ( ! $eventId || ! $requestKey ) {
			$this->error( 'Недостаточно данных' );
			return;
		}

		try {
			$this->registrationService->cancelBySelf( $ctx->personId, $eventId, $requestKey );
			// Собрать обновлённую карточку
			$personId = $ctx->personId;
			$exams = $this->examsService->build( $personId, false );
			$this->success( $exams );
		} catch ( CodedException $e ) {
			$this->fail( $e->code(), $e->getMessage() );
		} catch ( \Exception $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Резолвит person_id: ученик получает себя, родитель — только своего ребёнка.
	 */
	private function resolveSubjectPersonId( ?int $clientPersonId ): ?int {
		$ctx = $this->resolver->context( get_current_user_id() );
		return $ctx->resolveSubjectPersonId( $clientPersonId );
	}
}
