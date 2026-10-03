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

class LearnerExamCallbacks extends BaseController {

	use AjaxResponse, Sanitizer;

	public function __construct(
		private ?ProfileViewResolver $resolver = null,
		private ?LearnerExamsService $examsService = null
	) {
		$this->resolver ??= new ProfileViewResolver();
		$this->examsService ??= new LearnerExamsService();
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

		// TODO: Реализация в 5.3.2
		$this->success( array( 'registered' => true ) );
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

		// TODO: Реализация в 5.3.2
		$this->success( array( 'changed' => true ) );
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

		// TODO: Реализация в 5.3.2
		$this->success( array( 'cancelled' => true ) );
	}

	/**
	 * Резолвит person_id: ученик получает себя, родитель — только своего ребёнка.
	 */
	private function resolveSubjectPersonId( ?int $clientPersonId ): ?int {
		$ctx = $this->resolver->context( get_current_user_id() );
		return $ctx->resolveSubjectPersonId( $clientPersonId );
	}
}
