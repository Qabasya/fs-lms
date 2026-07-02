<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\AjaxResponse;
use Inc\Shared\Traits\Sanitizer;

class GradeAttemptCallbacks extends BaseController {

	use Authorizer;
	use AjaxResponse;
	use Sanitizer;

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentAnswerRepository  $answers,
		private readonly AutoGradeService            $autoGrade,
		private readonly ClockInterface              $clock,
		private readonly GroupAccessGuard            $guard,
		private readonly PostManager                 $posts,
	) {
		parent::__construct();
	}

	/**
	 * Преподаватель вручную оценивает один ответ попытки.
	 *
	 * Эпик 13 (D17): если у задачи заданы критерии — балл ставится ПОКРИТЕРИЙНО
	 * (`criteria_scores`, JSON `{индекс: баллы}`), `score`/`is_correct` с фронта
	 * игнорируются — итоговый балл = сумма по критериям (сырые баллы, без весов).
	 * Без критериев — прежнее поведение (один балл + флаг «верно»).
	 */
	public function ajaxGradeAttempt(): void {
		$this->authorize( Nonce::GradeAttempt, Capability::ManageLmsTeaching );

		$attemptId = $this->requireInt( 'attempt_id' );
		$taskId    = $this->requireInt( 'task_id' );
		$feedback  = $this->sanitizeText( 'feedback' );

		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt ) {
			$this->error( 'Попытка не найдена.' );
			return;
		}

		// Per-group scoping: попытка, привязанная к группе, — только для её ФАКТИЧЕСКОГО
		// преподавателя (T11.9); в период замены оригинал — read-only (T5.7).
		if ( $attempt->groupId && ! $this->guard->canWriteJournal( (int) $attempt->groupId, get_current_user_id() ) ) {
			$this->error( 'Нет доступа к этой группе.' );
			return;
		}

		$meta         = $this->posts->getMeta( $taskId, PostMetaName::Meta->value );
		$criteriaDefs = is_array( $meta ) && is_array( $meta['task_criteria']['criteria'] ?? null )
			? $meta['task_criteria']['criteria']
			: array();

		if ( ! empty( $criteriaDefs ) ) {
			$submittedRaw = $this->sanitizeText( 'criteria_scores' );
			$submitted    = json_decode( $submittedRaw, true );
			$submitted    = is_array( $submitted ) ? $submitted : array();

			$clamped  = array();
			$score    = 0.0;
			$maxScore = 0.0;
			foreach ( $criteriaDefs as $i => $def ) {
				$max     = (float) ( $def['max_points'] ?? 0 );
				$awarded = isset( $submitted[ $i ] ) ? max( 0.0, min( $max, (float) $submitted[ $i ] ) ) : 0.0;
				$clamped[ $i ] = $awarded;
				$score        += $awarded;
				$maxScore     += $max;
			}

			$this->answers->upsert( $attemptId, $taskId, [
				'score'             => $score,
				'max_score'         => $maxScore,
				'is_correct'        => $score >= $maxScore ? 1 : 0,
				'criteria_scores'   => wp_json_encode( $clamped ),
				'grader_note'       => $feedback,
				'graded_by_user_id' => get_current_user_id(),
				'graded_at'         => $this->clock->now(),
			] );
		} else {
			$score     = (float) $this->sanitizeText( 'score' );
			$isCorrect = (bool) $this->sanitizeInt( 'is_correct' );

			$this->answers->upsert( $attemptId, $taskId, [
				'score'             => $score,
				'is_correct'        => $isCorrect ? 1 : 0,
				'grader_note'       => $feedback,
				'graded_by_user_id' => get_current_user_id(),
				'graded_at'         => $this->clock->now(),
			] );
		}

		$updated = $this->autoGrade->finalize( $attempt );
		$this->success( [
			'attempt_status' => $updated->status->value,
			'total_score'    => $updated->totalScore,
		] );
	}
}
