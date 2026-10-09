<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\DTO\Course\GradeDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\WorkSourceType;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use Inc\Services\Course\WorkDetailService;
use Inc\Services\Course\WorkResetService;
use Inc\Services\Exam\ExamConductService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

class GradingCallbacks extends BaseController {

	use AjaxResponse;
	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly SubmissionService     $submissionService,
		private readonly GroupAccessGuard      $guard,
		private readonly SubmissionRepository  $submissionRepo,
		private readonly GroupLessonRepository $groupLessons,
		private readonly WorkDetailService     $workDetail,
		private readonly WorkResetService      $workReset,
		private readonly AssessmentAttemptRepository $attempts,
		private readonly ExamConductService    $examConduct,
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

		// Экзаменная попытка группы не имеет: доступ решает проведение, разбор строит проекция режима `manage` (8.4.2).
		if ( WorkSourceType::Attempt->value === $sourceType ) {
			$attempt = $this->attempts->find( $sourceId );
			if ( null !== $attempt && $attempt->isExam() ) {
				$this->sendExamDetail( $attempt );
				return;
			}
		}

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

	/**
	 * История прошлых раундов сдачи работы (.docs/Tasks.md, «Пройти заново») —
	 * read-only снимки предыдущих попыток для пилюль на экране проверки. Только
	 * для `submission` (работы); у экзаменов (`fs_lms_assessment_attempts`)
	 * каждая попытка и так отдельная запись, отдельная история не нужна.
	 * Params: submission_id.
	 */
	public function ajaxGetWorkAttemptHistory(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );

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

		$history = $this->workDetail->attemptHistory( $submissionId );
		if ( null === $history ) {
			$this->error( 'Сдача не найдена.' );
			return;
		}

		$this->success( array( 'attempts' => $history ) );
	}

	/**
	 * Сброс попыток/сдач ученика по работе или экзамену (задача 11): удаляет все
	 * попытки/сдачи с результатами, чтобы ученик прошёл заново. Params: source_type, source_id.
	 */
	public function ajaxResetAttempts(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$sourceType = $this->sanitizeText( 'source_type' );
		$sourceId   = $this->requireInt( 'source_id' );

		// Административный сброс экзаменной попытки — не обычная отмена брони: этого пути у экзамена нет (SPEC §7).
		if ( WorkSourceType::Attempt->value === $sourceType && $this->attempts->find( $sourceId )?->isExam() ) {
			$this->error( 'Экзаменную попытку сбросить нельзя.' );
			return;
		}

		$groupId = $this->workReset->groupIdFor( $sourceType, $sourceId );
		if ( null === $groupId ) {
			$this->error( 'Работа не найдена.' );
			return;
		}
		if ( ! $this->guard->canWriteJournal( $groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$deleted = $this->workReset->reset( $sourceType, $sourceId );
		if ( $deleted < 0 ) {
			$this->error( 'Не удалось сбросить попытки.' );
			return;
		}

		$this->success( array( 'deleted' => $deleted ) );
	}

	public function ajaxSaveGrade(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );
		$score        = $this->sanitizeFloat( 'score' );
		$maxScore     = $this->sanitizeFloat( 'max_score', 'POST', 100.0 );
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

	/**
	 * Закрывает проверку работы целиком (Tasks.md, п. 6): сдача уезжает из
	 * «На проверке» в «Проверенные». Params: submission_id.
	 */
	public function ajaxCompleteReview(): void {
		$this->authorize( Nonce::GradeWork, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );

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

		try {
			$this->submissionService->completeReview( $submissionId, get_current_user_id() );
			$this->success( array( 'submission_id' => $submissionId ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
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

	/** Разбор экзаменной попытки для экрана проверки; нет права на проведение — как «не найдена». */
	private function sendExamDetail( \Inc\DTO\Assessment\AttemptDTO $attempt ): void {
		try {
			$detail = $this->examConduct->reviewFor( get_current_user_id(), $attempt );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage() );
			return;
		}

		if ( null === $detail ) {
			$this->error( 'Работа не найдена.' );
			return;
		}

		$this->success( $detail );
	}
}
