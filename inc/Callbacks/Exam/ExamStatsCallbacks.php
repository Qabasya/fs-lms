<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Exam;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Exam\ExamStatsService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX экрана «Статистика» (этап 10): показатели проведения и разбор по заданиям.
 *
 * Право `ManageExams` и nonce `ExamManage`; доступ к конкретному проведению проверяет сервис. Ученикам, родителям и гостям экшен недоступен:
 * у них нет ни права, ни nonce сотрудника.
 */
class ExamStatsCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	private const AUDIENCES = array( 'student', 'guest', 'all' );

	public function __construct(
		private readonly ExamStatsService $stats,
	) {
		parent::__construct();
	}

	public function ajaxGetExamStats(): void {
		$this->authorize( Nonce::ExamManage, Capability::ManageExams );

		$audience = $this->sanitizeKey( 'audience' );
		$filters  = array(
			'subject_key' => $this->requireKey( 'subject_key' ),
			'event_id'    => $this->sanitizeInt( 'event_id' ),
			'session_id'  => $this->sanitizeInt( 'session_id' ),
			'audience'    => in_array( $audience, self::AUDIENCES, true ) ? $audience : 'all',
		);

		try {
			$this->success( $this->stats->overview( get_current_user_id(), $filters ) );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
		}
	}
}
