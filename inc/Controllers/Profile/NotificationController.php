<?php

declare( strict_types=1 );

namespace Inc\Controllers\Profile;

use Inc\Callbacks\Profile\NotificationCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class NotificationController
 *
 * Регистрирует AJAX-хуки уведомлений кабинета.
 *
 * @package Inc\Controllers\Profile
 *
 * Следует паттерну Template Method базового {@see AjaxController}: только
 * `ajaxActions()` (кабинет всегда залогинен — nopriv не нужен), регистрация — в родителе.
 */
class NotificationController extends AjaxController {

	public function __construct(
		private readonly NotificationCallbacks $callbacks,
	) {
		parent::__construct();
	}

	/**
	 * @return list<array{AjaxHook, object}>
	 */
	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetNotifications, $this->callbacks ),
			array( AjaxHook::GetNotificationsCount, $this->callbacks ),
			array( AjaxHook::MarkNotificationRead, $this->callbacks ),
			array( AjaxHook::MarkAllNotificationsRead, $this->callbacks ),
		);
	}
}
