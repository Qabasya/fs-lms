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
use Inc\Services\Course\SubmissionService;
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
	) {
		parent::__construct();
	}

	public function ajaxSaveGrade(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLMSAssignments );

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
		if ( ! $gl || ! $this->guard->canManage( $gl->groupId, get_current_user_id() ) ) {
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
		$this->authorize( Nonce::GradeWork, Capability::ManageLMSAssignments );

		$submissionId = $this->requireInt( 'submission_id' );
		$feedback     = $this->requireText( 'feedback' );

		$sub = $this->submissionRepo->find( $submissionId );
		if ( ! $sub ) {
			$this->error( 'Сдача не найдена.' );
			return;
		}

		$gl = $this->groupLessons->find( $sub->groupLessonId );
		if ( ! $gl || ! $this->guard->canManage( $gl->groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$this->submissionService->returnForRework( $submissionId, $feedback, get_current_user_id() );
		$this->success( array( 'submission_id' => $submissionId ) );
	}

	public function ajaxGetGroupSubmissions(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLMSAssignments );

		$groupId = $this->requireInt( 'group_id' );
		if ( ! $this->guard->canManage( $groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$queue = $this->submissionRepo->listQueueByGroup( $groupId );
		$this->success( array_map( fn( $s ) => array(
			'id'               => $s->id,
			'work_id'          => $s->workId,
			'work_type'        => $s->workType->value,
			'status'           => $s->status->value,
			'answer_text'      => $s->answerText,
			'attachment_id'    => $s->attachmentId,
			'submitted_at'     => $s->submittedAt,
			'is_late'          => $s->isLate(),
		), $queue ) );
	}

	public function ajaxGetGradebook(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLMSAssignments );

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
