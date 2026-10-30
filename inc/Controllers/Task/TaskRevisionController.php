<?php

declare( strict_types=1 );

namespace Inc\Controllers\Task;

use Inc\Contracts\ServiceInterface;
use Inc\Services\Task\TaskRevisionService;

/**
 * Class TaskRevisionController
 *
 * Подключает историю изменений заданий (ревизии WordPress с метой задания); логика — {@see TaskRevisionService}.
 *
 * @package Inc\Controllers\Task
 */
class TaskRevisionController implements ServiceInterface {

	public function __construct(
		private readonly TaskRevisionService $revisions,
	) {}

	public function register(): void {
		add_filter( 'wp_post_revision_meta_keys', array( $this->revisions, 'revisionMetaKeys' ), 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this->revisions, 'hasChanged' ), 10, 3 );
	}
}
