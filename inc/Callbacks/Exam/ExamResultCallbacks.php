<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamResultsViewService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX раздела «Результаты» (8.7): работы проведений предмета с фильтрами и очередью проверки.
 *
 * Право `ManageExams` и nonce `ExamManage`; список строит {@see ExamResultsViewService::results()} и включает только проведения,
 * доступные пользователю (`ExamAccessGuard::canManageEvent()`).
 */
class ExamResultCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	private const STATUSES  = array( 'pending_review', 'ready', 'approved', 'all' );
	private const AUDIENCES = array( 'student', 'guest', 'all' );

	public function __construct(
		private readonly ExamResultsViewService $results,
	) {
		parent::__construct();
	}

	public function ajaxGetExamResults(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$subjectKey = $this->requireKey( 'subject_key' );
		$status     = $this->sanitizeKey( 'status' );
		$audience   = $this->sanitizeKey( 'audience' );
		$filters    = array(
			'event_id'   => $this->sanitizeInt( 'event_id' ),
			'session_id' => $this->sanitizeInt( 'session_id' ),
			'source_id'  => $this->sanitizeInt( 'source_id' ),
			// Значение вне перечня не отбрасывает выборку молча, а превращается в «все».
			'status'     => in_array( $status, self::STATUSES, true ) ? $status : 'all',
			'audience'   => in_array( $audience, self::AUDIENCES, true ) ? $audience : 'all',
		);

		try {
			$this->success( $this->results->results( get_current_user_id(), $subjectKey, $filters ) );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		}
	}
}
