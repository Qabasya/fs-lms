<?php

declare( strict_types=1 );

namespace Inc\Controllers\Pages;

use Inc\Callbacks\Task\AllTasksCallbacks;
use Inc\Contracts\ServiceInterface;
use Inc\Core\BaseController;
use Inc\Enums\Wp\AjaxHook;
use Inc\Services\Task\TaskSearchIndexer;

/**
 * Class AllTasksPageController
 *
 * Контроллер публичной страницы «Все задания» (тренажёр).
 *
 * Регистрирует:
 *   - template_include — подмена шаблона на архиве CPT {subject}_tasks;
 *   - AJAX-хук постраничной подгрузки/фильтрации заданий (priv + nopriv);
 *   - обновление поискового индекса задания (post_content) при сохранении меты.
 *
 * @package Inc\Controllers\Pages
 */
class AllTasksPageController extends BaseController implements ServiceInterface {

	public function __construct(
		private readonly AllTasksCallbacks $callbacks,
		private readonly TaskSearchIndexer $indexer,
	) {
		parent::__construct();
	}

	public function register(): void {
		add_filter( 'template_include', array( $this->callbacks, 'loadAllTasksTemplate' ) );

		add_action(
			AjaxHook::FetchAllTasks->action(),
			array( $this->callbacks, AjaxHook::FetchAllTasks->callbackMethod() )
		);
		add_action(
			AjaxHook::FetchAllTasks->noPrivAction(),
			array( $this->callbacks, AjaxHook::FetchAllTasks->callbackMethod() )
		);

		// Поисковый индекс: зеркалим условие задания в post_content при сохранении меты.
		add_action( 'added_post_meta', array( $this->indexer, 'onMetaSaved' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this->indexer, 'onMetaSaved' ), 10, 3 );
	}
}