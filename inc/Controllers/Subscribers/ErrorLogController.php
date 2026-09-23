<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Callbacks\Log\ErrorLogCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class ErrorLogController
 *
 * Журнал «Ошибки»: подписка на ошибки AJAX-обработчиков и провалы nonce,
 * приём отчётов о сбоях из браузера. Логика — {@see ErrorLogCallbacks}.
 *
 * @package Inc\Controllers\Subscribers
 */
class ErrorLogController extends AjaxController {

	public function __construct(
		private readonly ErrorLogCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		parent::register();

		add_action( ErrorCode::HOOK, array( $this->callbacks, 'onError' ), 10, 4 );
		add_action( 'check_ajax_referer', array( $this->callbacks, 'onNonceCheck' ), 10, 2 );
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::ReportClientError, $this->callbacks ),
		);
	}
}
