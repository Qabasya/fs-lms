<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\ContentCloneService;

/**
 * Class CloneCallbacks
 *
 * AJAX-обработчики клонирования / форка контента (T1.5.11).
 *
 * @package Inc\Callbacks\Course
 */
class CloneCallbacks extends BaseController {

	public function __construct(
		private readonly ContentCloneService $cloneService,
	) {
		parent::__construct();
	}

	public function ajaxCloneLesson(): void {
		$this->authorize( Nonce::Subject, Capability::Admin );

		$id    = $this->requireInt( 'lesson_id' );
		$newId = $this->cloneService->cloneLesson( $id );

		if ( $newId <= 0 ) {
			$this->error( __( 'Не удалось клонировать урок.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'id' => $newId ) );
	}

	public function ajaxCloneWork(): void {
		$this->authorize( Nonce::Subject, Capability::Admin );

		$id    = $this->requireInt( 'work_id' );
		$newId = $this->cloneService->cloneWork( $id );

		if ( $newId <= 0 ) {
			$this->error( __( 'Не удалось клонировать работу.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'id' => $newId ) );
	}

	public function ajaxCloneAssessment(): void {
		$this->authorize( Nonce::Subject, Capability::Admin );

		$id    = $this->requireInt( 'assessment_id' );
		$newId = $this->cloneService->cloneAssessment( $id );

		if ( $newId <= 0 ) {
			$this->error( __( 'Не удалось клонировать контрольную.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'id' => $newId ) );
	}

	public function ajaxCloneCourse(): void {
		$this->authorize( Nonce::Subject, Capability::Admin );

		$id   = $this->requireInt( 'course_id' );
		$mode = $this->sanitizeKey( 'mode' );
		if ( ! in_array( $mode, array( 'shallow', 'deep' ), true ) ) {
			$mode = 'shallow';
		}

		$newId = $this->cloneService->cloneCourse( $id, $mode );

		if ( $newId <= 0 ) {
			$this->error( __( 'Не удалось клонировать курс.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'id' => $newId ) );
	}

	public function ajaxForkLessonForGroup(): void {
		$this->authorize( Nonce::Manager, Capability::AuthorLmsCourses );

		$groupId       = $this->requireInt( 'group_id' );
		$groupLessonId = $this->requireInt( 'group_lesson_id' );

		$forkId = $this->cloneService->forkLessonForGroup( $groupId, $groupLessonId );

		if ( $forkId <= 0 ) {
			$this->error( __( 'Не удалось форкнуть урок для группы.', 'fs-lms' ) );
			return;
		}

		$this->success( array( 'id' => $forkId ) );
	}
}
