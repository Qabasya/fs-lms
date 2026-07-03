<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Course\SubmissionService;
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
		private readonly SubmissionService $submissionService,
		private readonly PersonRepository  $persons,
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
		$answersRaw    = $this->sanitizeText( 'answers' );

		$answers = json_decode( $answersRaw, true );
		if ( ! is_array( $answers ) ) {
			$this->error( 'Неверный формат ответов.' );
			return;
		}

		$userId = get_current_user_id();
		$person = $this->persons->findByWpUserId( $userId );
		if ( ! $person ) {
			$this->error( 'Профиль не найден.' );
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
			) );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}

	/**
	 * Преподаватель оценивает один свободный ответ в пакетной сдаче.
	 *
	 * POST: submission_id, score, feedback, security
	 */
	public function ajaxGradeBatchTask(): void {
		$this->authorize( Nonce::GradeBatch, Capability::ManageLmsTeaching );

		$submissionId = $this->requireInt( 'submission_id' );
		$score        = (float) ( $_POST['score'] ?? 0 );
		$feedback     = $this->sanitizeHtml( 'feedback' );

		$teacherUserId = get_current_user_id();

		try {
			$this->submissionService->gradeBatchTask( $submissionId, $score, $feedback, $teacherUserId );
			$this->success( array( 'submission_id' => $submissionId ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->error( $e->getMessage() );
		}
	}
}
