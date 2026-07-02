<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\GradeDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\GradebookService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\ReviewQueueService;
use Inc\Services\Course\SubmissionService;
use Inc\Services\Course\WorkDetailService;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class GradingCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly SubmissionService     $submissionService,
		private readonly GradebookService      $gradebookService,
		private readonly GroupAccessGuard      $guard,
		private readonly SubmissionRepository  $submissionRepo,
		private readonly GroupLessonRepository $groupLessons,
		private readonly ReviewQueueService    $reviewQueue,
		private readonly WorkDetailService     $workDetail,
	) {
		parent::__construct();
	}

	/**
	 * Деталь работы для «Сводки по ученику» (Эпик 10 T10.9): условия задач,
	 * ответы ученика, вердикты и баллы. Params: source_type, source_id.
	 */
	public function ajaxGetWorkDetail(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$sourceType = $this->sanitizeText( 'source_type' );
		$sourceId   = $this->requireInt( 'source_id' );

		$detail = $this->workDetail->forWork( $sourceType, $sourceId );
		if ( null === $detail ) {
			$this->error( 'Работа не найдена.' );
			return;
		}
		if ( ! $this->guard->canManage( (int) $detail['group_id'], get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}
		unset( $detail['group_id'] );

		$this->success( $detail );
	}

	public function ajaxSaveGrade(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );
		$score        = (float) ( $_POST['score'] ?? 0 );
		$maxScore     = (float) ( $_POST['max_score'] ?? 100 );
		$feedback     = $this->sanitizeText( 'feedback' );

		$sub = $this->submissionRepo->find( $submissionId );
		if ( ! $sub ) {
			$this->error( 'Сдача не найдена.' );
			return;
		}

		$gl = $this->groupLessons->find( $sub->groupLessonId );
		if ( ! $gl || ! $this->guard->canWriteJournal( $gl->groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$this->submissionService->grade(
			$submissionId,
			new GradeDTO( score: $score, maxScore: $maxScore, feedback: $feedback ?: null ),
			get_current_user_id()
		);
		$this->success( array( 'submission_id' => $submissionId ) );
	}

	public function ajaxReturnSubmission(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );
		$feedback     = $this->requireText( 'feedback' );

		$sub = $this->submissionRepo->find( $submissionId );
		if ( ! $sub ) {
			$this->error( 'Сдача не найдена.' );
			return;
		}

		$gl = $this->groupLessons->find( $sub->groupLessonId );
		if ( ! $gl || ! $this->guard->canWriteJournal( $gl->groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$this->submissionService->returnForRework( $submissionId, $feedback, get_current_user_id() );
		$this->success( array( 'submission_id' => $submissionId ) );
	}

	public function ajaxGetGroupSubmissions(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$groupId = $this->requireInt( 'group_id' );
		if ( ! $this->guard->canManage( $groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$this->success( $this->reviewQueue->forGroup( $groupId ) );
	}

	public function ajaxGetGradebook(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$groupId = $this->sanitizeInt( 'group_id' );
		if ( $groupId && ! $this->guard->canManage( $groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$entries = $groupId
			? $this->gradebookService->forGroup( $groupId )
			: array();

		$this->success( array_map( fn( $e ) => array(
			'student_person_id' => $e->studentPersonId,
			'group_id'          => $e->groupId,
			'source_type'       => $e->sourceType,
			'source_id'         => $e->sourceId,
			'title'             => $e->title,
			'category'          => $e->category,
			'score'             => $e->score,
			'max_score'         => $e->maxScore,
			'graded_at'         => $e->gradedAt,
			'display_type'      => $e->displayType,
			'display_value'     => $e->displayValue(),
		), $entries ) );
	}
}
