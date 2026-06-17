<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Services\Course\WorkAuthoringService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class WorkCallbacks
 *
 * Admin-AJAX обработчики конструктора работы (селектор заданий).
 *
 * @package Inc\Callbacks\Course
 */
class WorkCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly WorkAuthoringService $authoringService,
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
}
