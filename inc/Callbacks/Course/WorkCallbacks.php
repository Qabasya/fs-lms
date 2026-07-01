<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\WorkManager;
use Inc\Services\Course\WorkAuthoringService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class WorkCallbacks
 *
 * Admin-AJAX обработчики конструктора работы.
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
	 * AJAX-автосейв степ-листа работы: упорядоченные `item_ids` (задания/задачи).
	 * Params: work_id, item_ids[]
	 */
	public function ajaxSaveWorkItems(): void {
		$this->authorize( Nonce::AuthorWork, Capability::AuthorLmsCourses );

		$work_id  = $this->requireInt( 'work_id' );
		$item_ids = array_map( 'intval', (array) ( $_POST['item_ids'] ?? array() ) );

		if ( $this->workManager->setItemIds( $work_id, $item_ids ) ) {
			$this->success( array( 'count' => count( array_filter( $item_ids ) ) ) );
		} else {
			$this->error( 'Работа не найдена.' );
		}
	}

	/**
	 * Кандидаты-задания для работы (только {key}_tasks текущего предмета).
	 * Params: subject_key, task_type, collection, scope, search
	 */
	public function ajaxGetWorkTaskCandidates(): void {
		$this->authorize( Nonce::AuthorWork, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$task_type   = (int) ( $_POST['task_type'] ?? 0 );
		$collection  = (int) ( $_POST['collection'] ?? 0 );
		$scope       = $this->sanitizeKey( 'scope' );
		$search      = $this->sanitizeText( 'search' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getTaskCandidates( $subject_key, $task_type, $collection, $scope, $search )
		);
	}

	/**
	 * Кандидаты-элементы для работы: {key}_tasks + fs_lms_problems (unified).
	 * Params: subject_key, collection, scope, search
	 */
	public function ajaxGetWorkItemCandidates(): void {
		$this->authorize( Nonce::AuthorWork, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$collection  = (int) ( $_POST['collection'] ?? 0 );
		$scope       = $this->sanitizeKey( 'scope' );
		$search      = $this->sanitizeText( 'search' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getItemCandidates( $subject_key, $collection, $scope, $search )
		);
	}

	/**
	 * Коллекции заданий предмета для фильтра.
	 * Params: subject_key
	 */
	public function ajaxGetWorkCollections(): void {
		$this->authorize( Nonce::AuthorWork, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );

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
		$this->authorize( Nonce::AuthorWork, Capability::AuthorLmsCourses );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' ) ?: 'Новая работа';
		$work_type   = $this->sanitizeKey( 'work_type' );

		$dto = WorkDTO::fromArray( array(
			'id'           => 0,
			'subject_key'  => $subject_key,
			'title'        => $title,
			'work_type'    => $work_type,
			'item_ids'     => array(),
			'instructions' => '',
			'author_id'    => get_current_user_id(),
			'status'       => 'draft',
		) );

		try {
			$work_id = $this->workManager->create( $subject_key, $dto );
			$this->success( array( 'id' => $work_id, 'title' => $title ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать работу: ' . $e->getMessage() );
		}
	}

	/**
	 * Создаёт черновик задачи (fs_lms_problems) из конструктора работы.
	 * Params: title
	 */
	public function ajaxCreateProblemDraft(): void {
		$this->authorize( Nonce::AuthorWork, Capability::AuthorLmsCourses );

		$title = $this->sanitizeText( 'title' ) ?: 'Новая задача';

		try {
			$id = $this->authoringService->createProblemDraft( $title );
			$this->success( array( 'id' => $id, 'title' => $title ) );
		} catch ( \Throwable $e ) {
			$this->error( 'Не удалось создать задачу: ' . $e->getMessage() );
		}
	}
}
