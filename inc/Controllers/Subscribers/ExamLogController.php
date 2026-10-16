<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Callbacks\Log\ExamLogCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class ExamLogController
 *
 * Журнал «Экзамены»: подписка на действия служб экзаменов. Логика — {@see ExamLogCallbacks}.
 *
 * @package Inc\Controllers\Subscribers
 */
class ExamLogController extends AjaxController {

	public function __construct(
		private readonly ExamLogCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		parent::register();

		add_action( ExamLogAction::HOOK, array( $this->callbacks, 'onAction' ), 10, 2 );
	}

	protected function publicAjaxActions(): array {
		return array(
			array( AjaxHook::LogExamClientEvent, $this->callbacks ),
		);
	}
}
