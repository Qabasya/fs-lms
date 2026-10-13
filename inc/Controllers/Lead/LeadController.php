<?php

declare( strict_types=1 );

namespace Inc\Controllers\Lead;

use Inc\Callbacks\Lead\LeadCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class LeadController
 *
 * Заявки с лид-форм сайта: подписка на фильтр темы `fs_lms_theme_lead_submitted`
 * и AJAX удаления для вкладки «Заявки с сайта».
 *
 * @package Inc\Controllers\Lead
 */
class LeadController extends AjaxController {

	public function __construct(
		private readonly LeadCallbacks $callbacks,
	) {
		parent::__construct();
	}

	public function register(): void {
		// Фильтр: тема по ответу `true` узнаёт, что заявка сохранена, и не пугает посетителя
		// ошибкой, если письмо не ушло.
		add_filter( 'fs_lms_theme_lead_submitted', array( $this->callbacks, 'onThemeLead' ), 10, 2 );

		parent::register();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::DeleteLeads, $this->callbacks ),
			array( AjaxHook::DeleteRejectedLeads, $this->callbacks ),
		);
	}
}
