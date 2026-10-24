<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Callbacks\Task\TaskBulkEditCallbacks;
use Inc\Controllers\System\AjaxController;
use Inc\Enums\Wp\AjaxHook;

/**
 * Class TaskBulkEditController
 *
 * Регистрирует AJAX-хуки массовой правки заданий через CSV.
 *
 * @package Inc\Controllers\Task
 */
class TaskBulkEditController extends AjaxController {

	public function __construct(
		private readonly TaskBulkEditCallbacks $callbacks,
	) {
		parent::__construct();
	}

	protected function ajaxActions(): array {
		return array(
			array( AjaxHook::TaskBulkTemplate, $this->callbacks ),
			array( AjaxHook::TaskBulkPreview, $this->callbacks ),
			array( AjaxHook::TaskBulkApply, $this->callbacks ),
			array( AjaxHook::TaskBulkSnapshots, $this->callbacks ),
			array( AjaxHook::TaskBulkSnapshotCsv, $this->callbacks ),
			array( AjaxHook::TaskBulkRollback, $this->callbacks ),
		);
	}
}
