<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Capability;
use Inc\Enums\Nonce;
use Inc\Services\Course\CourseAuthoringService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class CourseCallbacks
 *
 * Admin-AJAX обработчики конструктора курса (селектор уроков).
 *
 * @package Inc\Callbacks\Course
 */
class CourseCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly CourseAuthoringService $authoringService,
	) {
		parent::__construct();
	}

	/**
	 * Кандидаты-уроки для курса.
	 * Params: subject_key, scope (mine|subject), search
	 */
	public function ajaxGetCourseLessonCandidates(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( $_POST['subject_key'] ?? '' );
		$scope       = $this->sanitizeKey( $_POST['scope'] ?? 'mine' );
		$search      = $this->sanitizeText( $_POST['search'] ?? '' );

		if ( ! in_array( $scope, array( 'mine', 'subject' ), true ) ) {
			$scope = 'mine';
		}

		$this->success(
			$this->authoringService->getLessonCandidates( $subject_key, $scope, $search )
		);
	}
}
