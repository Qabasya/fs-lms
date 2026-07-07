<?php

declare( strict_types=1 );

namespace Inc\Controllers\Course;

use Inc\Controllers\System\AjaxController;
use Inc\Callbacks\Course\PreviewSolveCallbacks;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class PreviewSolveController
 *
 * Регистрирует dry-run эндпоинты предпросмотра курса (#5): проверка заданий и
 * работ без сохранения. Гейт (нонс + право `AuthorLmsCourses`) — в самих
 * коллбеках, поэтому это priv-only AJAX (ученику недоступно).
 *
 * @package Inc\Controllers\Course
 */
class PreviewSolveController extends AjaxController {

	public function __construct(
		private readonly PreviewSolveCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::PreviewCheckTask,       $this->callbacks ),
			array( AjaxHook::PreviewCheckWork,       $this->callbacks ),
			array( AjaxHook::PreviewCheckAssessment, $this->callbacks ),
		);
	}
}
