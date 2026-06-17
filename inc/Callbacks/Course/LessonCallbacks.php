<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Services\Course\LessonAuthoringService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class LessonCallbacks
 *
 * Admin-AJAX обработчики для конструктора бакетов урока.
 *
 * @package Inc\Callbacks\Course
 */
class LessonCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly LessonAuthoringService $authoringService,
	) {
		parent::__construct();
	}

	/**
	 * Список кандидатов-работ для селектора урока.
	 * Params: subject_key, work_type (string), scope (mine|subject), search (string)
	 */
	public function ajaxGetLessonWorkCandidates(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$work_type   = $this->sanitizeKey( $_POST['work_type'] ?? '' );
		$scope       = $this->sanitizeKey( $_POST['scope'] ?? 'mine' );
		$search      = $this->sanitizeText( $_POST['search'] ?? '' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getWorkCandidates( $subject_key, $work_type, $scope, $search )
		);
	}

	/**
	 * Статьи предмета для ArticleRefField.
	 * Params: subject_key
	 */
	public function ajaxGetLessonArticles(): void {
		$this->authorize( Nonce::AuthorLesson, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$articles    = $this->authoringService->getArticles( $subject_key );

		$result = array();
		foreach ( $articles as $id => $title ) {
			$result[] = array( 'id' => $id, 'title' => $title );
		}

		$this->success( $result );
	}
}
