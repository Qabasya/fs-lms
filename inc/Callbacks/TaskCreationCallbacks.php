<?php

declare( strict_types=1 );

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\Nonce;
use Inc\Managers\TaskManager;
use Inc\Repositories\BoilerplateRepository;
use Inc\Services\Task\TaskTypeService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class TaskCreationCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TaskTypeService      $task_types,
		private readonly BoilerplateRepository $boilerplates,
		private readonly TaskManager          $taskManager,
	) {
		parent::__construct();
	}

	public function ajaxCreateTask(): void {
		$this->authorize( Nonce::TaskCreation );

		$subject_key     = $this->requireKey( 'subject_key', error: 'Не указан предмет. #TCC134' );
		$term_id         = $this->requireInt( 'term_id', error: 'Не выбран тип задания. #TCC134' );
		$title           = $this->sanitizeText( 'title' ) ?: 'Новое задание';
		$boilerplate_uid = $this->sanitizeText( 'boilerplate_uid' );

		try {
			$new_id = $this->taskManager->createNewTask(
				$subject_key,
				$term_id,
				$title,
				$boilerplate_uid
			);

			$this->success( array( 'redirect' => get_edit_post_link( $new_id, 'abs' ) ) );

		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать задание: ' . $e->getMessage() );
		}
	}

	public function ajaxGetTaskTypes(): void {
		$this->authorize( Nonce::TaskCreation );

		$subject_key = $this->requireKey( 'subject_key', 'GET', 'Предмет не указан' );

		$this->success( $this->task_types->getTaskTypes( $subject_key ) );
	}

	public function ajaxGetTaskBoilerplates(): void {
		$this->authorize( Nonce::TaskCreation );

		$subject_key = $this->requireKey( 'subject_key', 'GET' );
		$term_slug   = $this->requireKey( 'term_slug', 'GET' );

		$variants = $this->boilerplates->getBoilerplates( $subject_key, $term_slug );

		$response = array_map(
			static fn( $bp ) => array( 'uid' => $bp->uid, 'title' => $bp->title ),
			$variants
		);

		$this->success( $response );
	}
}
