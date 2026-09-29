<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\CourseManager;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\CoursePreviewService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX страницы курса преподавателя (Tasks.md З4): программа курса — модули и
 * уроки, как «Мои курсы» ученика, но без прогресса. Урок открывается в
 * preview-плеере, поэтому доступ — тот же гард, что у предпросмотра.
 *
 * @package Inc\Callbacks\Profile
 */
class TeacherCoursesCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly CourseManager            $courses,
		private readonly CoursePreviewService     $preview,
		private readonly CoursePreviewAccessGuard $guard,
	) {
		parent::__construct();
	}

	/** Params: course_id */
	public function ajaxGetTaughtCourseProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );

		$courseId = $this->requireInt( 'course_id' );
		$course   = $this->courses->get( $courseId );

		if ( null === $course || ! $this->guard->canPreview( get_current_user_id(), $courseId ) ) {
			$this->error( __( 'Курс недоступен.', 'fs-lms' ) );
		}

		$this->success( array( 'modules' => $this->preview->program( $course ) ) );
	}
}
