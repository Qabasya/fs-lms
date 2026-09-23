<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Course\SubmissionService;
use Inc\Shared\CodedException;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class BatchSubmissionCallbacks
 *
 * AJAX-обработчики пакетной сдачи работы и ручной оценки свободных ответов (Этап 7).
 *
 * @package Inc\Callbacks\Course
 */
class BatchSubmissionCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly SubmissionService     $submissionService,
		private readonly PersonRepository      $persons,
		private readonly SubmissionRepository  $submissionRepo,
		private readonly GroupLessonRepository $groupLessons,
		private readonly GroupAccessGuard      $guard,
		private readonly WorkManager           $works,
	) {
		parent::__construct();
	}

	/**
	 * Ученик сдаёт всю работу одной кнопкой.
	 *
	 * POST: group_lesson_id, work_id, answers (JSON: {"taskId": answer, ...}), security
	 */
	public function ajaxSubmitBatchWork(): void {
		Nonce::SubmitBatchWork->verify();

		$groupLessonId = $this->requireInt( 'group_lesson_id' );
		$workId        = $this->requireInt( 'work_id' );
		$answersRaw    = $this->sanitizeAnswerText( 'answers' );

		// Подробности для журнала «Ошибки»: по ним отказ находится без расспросов ученика.
		$logContext = array(
			'group_lesson_id' => $groupLessonId,
			'work_id'         => $workId,
		);

		$answers = json_decode( $answersRaw, true );
		if ( ! is_array( $answers ) ) {
			$this->fail( ErrorCode::WorkFormat, 'Неверный формат ответов.', $logContext );
			return;
		}

		$userId = get_current_user_id();
		$person = $this->persons->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->fail( ErrorCode::WorkProfile, 'Профиль не найден.', $logContext );
			return;
		}

		// Работу сдаёт только ученик занятия: преподаватель открывает тот же урок
		// в teacher-режиме плеера, и его «Завершить работу» уходит в dry-run
		// (PreviewCheckWork) — но ручка обязана держаться и сама.
		$groupLesson = $this->groupLessons->find( $groupLessonId );
		if ( ! $groupLesson || ! $this->guard->isMemberEver( $groupLesson->groupId, $person->id ) ) {
			$this->fail( ErrorCode::WorkNotMember, 'Работу на этом занятии сдают только его ученики.', $logContext );
			return;
		}

		try {
			$aggregate = $this->submissionService->submitBatch(
				$person->id,
				$groupLessonId,
				$workId,
				$answers,
			);

			// T14.11: пооответные вердикты батч-проверки (агрегатная строка хранит
			// их JSON в answer_text) — плеер строит из них экран результатов.
			$perTask = json_decode( (string) $aggregate->answerText, true );

			$this->success( array(
				'submission_id' => $aggregate->id,
				'status'        => $aggregate->status->value,
				'status_label'  => $aggregate->status->label(),
				'correct'       => (int) ( $aggregate->score ?? 0 ),
				'total'         => (int) ( $aggregate->maxScore ?? 0 ),
				'tally'         => ( (int) ( $aggregate->score ?? 0 ) ) . '/' . ( (int) ( $aggregate->maxScore ?? 0 ) ),
				'per_task'      => is_array( $perTask ) ? $perTask : array(),
				'submitted_at'  => $aggregate->submittedAt,
				// Лимит сдач — настройка работы (0 = без ограничений): плеер обновляет
				// предупреждение и прячет «Пройти заново», не перезагружая урок.
				'attempts_used' => $this->submissionService->workAttemptsUsed( $person->id, $groupLessonId, $workId ),
				'max_attempts'  => $this->works->get( $workId )?->maxAttempts ?? 0,
				// Засчитанные задания при пересдаче заблокированы (.docs/Tasks.md, п. 1).
				'locked_task_ids' => $this->submissionService->lockedTaskIds( $person->id, $groupLessonId, $workId ),
			) );
		} catch ( CodedException $e ) {
			$this->fail( $e->errorCode, $e->getMessage(), $logContext );
		} catch ( \InvalidArgumentException $e ) {
			$this->fail( ErrorCode::Ajax, $e->getMessage(), $logContext );
		}
	}

	/**
	 * Преподаватель оценивает одно ручное задание («Развёрнутый ответ») в пакетной
	 * сдаче — поштучное оценивание submission-работы (D4, .docs/Tasks.md), та же
	 * проверка доступа, что у ajaxSaveGrade().
	 *
	 * POST: submission_id, score, feedback, security
	 */
	public function ajaxGradeBatchTask(): void {
		$this->authorize( Nonce::GradeBatch, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );
		$score        = $this->sanitizeFloat( 'score' );
		$feedback     = $this->sanitizeHtml( 'feedback' );

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

		$teacherUserId = get_current_user_id();

		try {
			$this->submissionService->gradeBatchTask( $submissionId, $score, $feedback, $teacherUserId );
			$this->success( array( 'submission_id' => $submissionId ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}
