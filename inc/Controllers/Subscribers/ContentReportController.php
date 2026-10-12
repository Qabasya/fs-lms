<?php

declare( strict_types=1 );

namespace Inc\Controllers\Subscribers;

use Inc\Callbacks\Log\ContentReportCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class ContentReportController
 *
 * Журнал «Найденные ошибки»: приём сообщений читателей (priv + nopriv).
 * Логика — {@see ContentReportCallbacks}.
 *
 * @package Inc\Controllers\Subscribers
 */
class ContentReportController extends AjaxController {

	public function __construct(
		private readonly ContentReportCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function publicAjaxActions(): array {
		return array(
			array( AjaxHook::ReportContentError, $this->callbacks ),
		);
	}
}
