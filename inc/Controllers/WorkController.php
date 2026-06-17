<?php

declare( strict_types=1 );

namespace Inc\Controllers;

use Inc\Callbacks\Course\WorkCallbacks;
use Inc\Enums\AjaxHook;

/**
 * Class WorkController
 *
 * Регистрирует AJAX-хуки конструктора работы.
 *
 * @package Inc\Controllers
 */
class WorkController extends AjaxController {

	public function __construct(
		private readonly WorkCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::GetWorkTaskCandidates, $this->callbacks ),
		);
	}
}
