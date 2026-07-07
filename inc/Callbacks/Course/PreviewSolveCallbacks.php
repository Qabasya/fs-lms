<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Course\ProgressStatus;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\BatchCheckService;
use Inc\Services\Task\TaskCheckerRegistry;
use Inc\Services\Template\TemplateResolver;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class PreviewSolveCallbacks
 *
 * Dry-run проверка ответов в предпросмотре курса (#5): преподаватель/методист/
 * автор прорешивает задания и работы, видит вердикт — но НИЧЕГО не сохраняется
 * (ни попыток, ни оценок, ни прогресса). Переиспользует те же чистые проверяющие,
 * что и штатная сдача (`TaskCheckerRegistry`, `BatchCheckService`), только не
 * пишет результат.
 *
 * Гейт — право `AuthorLmsCourses`: у обычного ученика его нет, поэтому обойти
 * сохранение штатной сдачи через эти эндпоинты он не может.
 *
 * @package Inc\Callbacks\Course
 */
class PreviewSolveCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly PostManager         $posts,
		private readonly TaskCheckerRegistry $checkers,
		private readonly TemplateResolver    $resolver,
		private readonly BatchCheckService   $batchCheck,
		private readonly AssessmentManager   $assessments,
	) {
		parent::__construct();
	}

	/**
	 * Проверка одного задания без сохранения.
	 *
	 * POST: ref (task post id), answer (JSON-encoded mixed).
	 */
	public function ajaxPreviewCheckTask(): void {
		$this->authorize( Nonce::PreviewSolve, Capability::AuthorLmsCourses );

		$taskId    = $this->requireInt( 'ref' );
		$rawAnswer = $this->sanitizeText( 'answer' );

		$task = $this->posts->get( $taskId );
		if ( ! $task ) {
			$this->error( 'Задание не найдено.' );
			return;
		}

		$checker = $this->checkers->get( $this->resolver->resolveEnum( $task ) );
		if ( null === $checker ) {
			$this->error( 'Задание проверяется вручную и не поддерживает авто-проверку.' );
			return;
		}

		$taskMeta = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$answer   = json_decode( $rawAnswer, true ) ?? $rawAnswer;
		$result   = $checker->check( is_array( $taskMeta ) ? $taskMeta : array(), $answer );

		// Dry-run: без попыток/прогресса/эталона. Шаг остаётся «доступным», чтобы
		// плеер не блокировал повторные прогоны (сохранения всё равно нет).
		$this->success( array(
			'is_correct'     => $result->isCorrect,
			'score'          => $result->score,
			'max_score'      => $result->maxScore,
			'item_feedback'  => $result->itemFeedback,
			'attempt_number' => 1,
			'attempts_used'  => 0,
			'max_attempts'   => 0,
			'reveal_hint'    => false,
			'step_status'    => ProgressStatus::Available->value,
		) );
	}

	/**
	 * Проверка работы (набора заданий) без сохранения.
	 *
	 * POST: ref (work post id — для симметрии, проверка идёт по answers), answers
	 * (JSON: {"taskId": answer, ...}).
	 */
	public function ajaxPreviewCheckWork(): void {
		$this->authorize( Nonce::PreviewSolve, Capability::AuthorLmsCourses );

		$answers = json_decode( $this->sanitizeText( 'answers' ), true );
		if ( ! is_array( $answers ) ) {
			$this->error( 'Неверный формат ответов.' );
			return;
		}

		$result = $this->batchCheck->check( $answers );

		$this->success( array(
			'status'       => 'preview',
			'status_label' => __( 'Предпросмотр', 'fs-lms' ),
			'correct'      => (int) $result->correctCount,
			'total'        => (int) $result->totalCount,
			'per_task'     => $result->perTask,
			'submitted_at' => null,
		) );
	}

	/**
	 * Проверка контрольной/экзамена (набора заданий) без сохранения.
	 *
	 * POST: ref (assessment post id), answers (JSON: {"taskId": answer, ...}).
	 * Веса заданий и вид контрольной берём из самой контрольной — клиенту не
	 * доверяем (ЕГЭ-композит и per-task-баллы считаются корректно).
	 */
	public function ajaxPreviewCheckAssessment(): void {
		$this->authorize( Nonce::PreviewSolve, Capability::AuthorLmsCourses );

		$assessment = $this->assessments->get( $this->requireInt( 'ref' ) );
		$answers    = json_decode( $this->sanitizeText( 'answers' ), true );
		if ( null === $assessment || ! is_array( $answers ) ) {
			$this->error( 'Контрольная не найдена или ответы некорректны.' );
			return;
		}

		$result = $this->batchCheck->check( $answers, $assessment->taskPoints, $assessment->kind );

		$this->success( array(
			'status'       => 'preview',
			'status_label' => __( 'Предпросмотр', 'fs-lms' ),
			'correct'      => (int) $result->correctCount,
			'total'        => (int) $result->totalCount,
			'per_task'     => $result->perTask,
			'submitted_at' => null,
		) );
	}
}
