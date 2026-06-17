<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Managers\WorkManager;
use Inc\Services\Course\WorkAuthoringService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class WorkCallbacks
 *
 * Admin-AJAX обработчики конструктора работы (селектор заданий, коллекции, создание черновика).
 *
 * @package Inc\Callbacks\Course
 */
class WorkCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly WorkAuthoringService $authoringService,
		private readonly WorkManager          $workManager,
	) {
		parent::__construct();
	}

	/**
	 * Кандидаты-задания для работы.
	 * Params: subject_key, task_type, collection, scope (mine|subject), search
	 */
	public function ajaxGetWorkTaskCandidates(): void {
		$this->authorize( Nonce::AuthorWork, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$task_type   = (int) ( $_POST['task_type'] ?? 0 );
		$collection  = (int) ( $_POST['collection'] ?? 0 );
		$scope       = $this->sanitizeKey( $_POST['scope'] ?? 'mine' );
		$search      = $this->sanitizeText( $_POST['search'] ?? '' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getTaskCandidates( $subject_key, $task_type, $collection, $scope, $search )
		);
	}

	/**
	 * Коллекции (пользовательские таксономии) заданий предмета для фильтра.
	 * Params: subject_key
	 */
	public function ajaxGetWorkCollections(): void {
		$this->authorize( Nonce::AuthorWork, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );

		$raw    = $this->authoringService->getCollections( $subject_key );
		$result = array();
		foreach ( $raw as $id => $name ) {
			$result[] = array( 'id' => $id, 'name' => $name );
		}

		$this->success( $result );
	}

	/**
	 * Создаёт черновик работы из конструктора урока.
	 * Params: subject_key, title, work_type
	 */
	public function ajaxCreateWorkDraft(): void {
		$this->authorize( Nonce::AuthorWork, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$title       = $this->sanitizeText( $_POST['title'] ?? '' ) ?: 'Новая работа';
		$work_type   = $this->sanitizeKey( $_POST['work_type'] ?? '' );

		$dto = WorkDTO::fromArray( array(
			'id'          => 0,
			'subject_key' => $subject_key,
			'title'       => $title,
			'work_type'   => $work_type,
			'task_ids'    => array(),
			'instructions'=> '',
			'author_id'   => get_current_user_id(),
			'status'      => 'draft',
		) );

		try {
			$work_id = $this->workManager->create( $subject_key, $dto );
			$this->success( array( 'id' => $work_id, 'title' => $title ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать работу: ' . $e->getMessage() );
		}
	}
}
