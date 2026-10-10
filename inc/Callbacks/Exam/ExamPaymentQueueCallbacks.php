<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Exam\ManualResolutionKind;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamPaymentQueueService;
use Inc\Services\Exam\GuestApplicationService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX очереди «Оплачено, требуется помощь» (этап 8.8.3).
 *
 * Достаточно любого из прав `ResolveExamPayments` (офис, администратор) или `ManageExams` (преподаватель); **область** (какое проведение) решает сервис.
 * Офис видит только эту очередь и не получает прав создавать или публиковать проведения: экшены управления проведением требуют `ManageExams`.
 * Nonce — `ExamManage` общего блока кабинета экзаменов.
 */
class ExamPaymentQueueCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	private const KINDS = array(
		ManualResolutionKind::Transferred,
		ManualResolutionKind::RefundedOutside,
		ManualResolutionKind::Other,
	);

	public function __construct(
		private readonly ExamPaymentQueueService $queue,
		private readonly GuestApplicationService $applications,
	) {
		parent::__construct();
	}

	public function ajaxGetExamPaymentQueue(): void {
		$this->authorizeAny( Nonce::ExamManage, array( Capability::ResolveExamPayments, Capability::ManageExams ) );

		$tab = $this->sanitizeKey( 'tab' );

		$this->success( $this->queue->list( get_current_user_id(), ExamPaymentQueueService::TAB_RESOLVED === $tab ? $tab : ExamPaymentQueueService::TAB_NEEDS_HELP ) );
	}

	public function ajaxResolveExamPayment(): void {
		$this->authorizeAny( Nonce::ExamManage, array( Capability::ResolveExamPayments, Capability::ManageExams ) );

		$applicationId = $this->requireInt( 'application_id' );
		$kind          = ManualResolutionKind::tryFrom( $this->sanitizeKey( 'kind' ) );
		$sessionId     = $this->sanitizeInt( 'session_id' );
		$reason        = $this->sanitizeMultilineText( 'reason' );
		$amount        = $this->sanitizeText( 'amount' );

		if ( null === $kind || ! in_array( $kind, self::KINDS, true ) ) {
			$this->error( 'Выберите, как урегулировать заявку.' );
			return;
		}

		try {
			$this->applications->resolve( get_current_user_id(), $applicationId, $kind, $sessionId > 0 ? $sessionId : null, $reason, '' !== $amount ? $amount : null );
			$this->success( $this->queue->list( get_current_user_id(), ExamPaymentQueueService::TAB_NEEDS_HELP ) );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		}
	}
}
