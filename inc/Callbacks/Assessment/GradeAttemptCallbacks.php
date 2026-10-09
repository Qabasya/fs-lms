<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Assessment;

use Inc\Contracts\ClockInterface;
use Inc\Core\BaseController;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Access\Capability;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Subject\TaskTemplate;
use Inc\Enums\Wp\Nonce;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Assessment\AutoGradeService;
use Inc\Services\Course\GroupAccessGuard;
use Inc\Services\Exam\ExamApprovalService;
use Inc\Services\Exam\ExamConductService;
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
		private readonly ExamConductService          $examConduct,
		private readonly ExamApprovalService         $examApproval,
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

		if ( ! $this->canGrade( $attempt, get_current_user_id() ) ) {
			$this->error( $attempt->isExam() ? 'Нет доступа к этому проведению.' : 'Нет доступа к этой группе.' );
			return;
		}

		// Экзаменная попытка: оценка меняет версию результата, чтобы два проверяющих не перезаписали друг друга (8.4.5).
		// Утверждённая работа правится только исправлением результата (с причиной и журналом), не обычной оценкой.
		if ( $attempt->isExam() && ! $this->claimExamVersion( $attempt ) ) {
			return;
		}

		// Произвольный балл — только для заданий без авто-чекера (TaskCheckerRegistry).
		// 2026-08-21: без этого гейта ручной балл на автопроверяемое задание уходил
		// в assessment_answers.score и расходился с реальной авто-проверкой — лист
		// результатов станции (KegeResultSheetService) пересчитывает по эталону и
		// ручной балл игнорирует, а журнал/«Мои оценки» его учитывали, отсюда два
		// разных итога на одну и ту же попытку.
		//
		// Исключение — явный ЗАЧЁТ задания целиком (`credit=1`, Tasks.md, п. 6):
		// опечатка в условии не должна стоить ученику балла. Расхождения с листом
		// станции здесь нет: `graded_by_user_id` в строке ответа делает балл
		// авторитетным и для него ({@see KegeResultSheetService::assemble()}).
		$isCredit = (bool) $this->sanitizeInt( 'credit' );
		$isManual = TaskTemplate::fromDatabase(
			(string) $this->posts->getMeta( $taskId, PostMetaName::TemplateType->value )
		)->needsManualReview();
		if ( ! $isManual && ! $isCredit ) {
			$this->error( 'Это задание проверяется автоматически — ручная оценка недоступна.' );
			return;
		}

		// Зачёт автопроверяемого задания: балл — максимум ответа (у станции он
		// приходит из task_points, иначе 1), критерии и произвольный score с
		// фронта не читаются вовсе.
		if ( $isCredit && ! $isManual ) {
			$existing = $this->answers->findByAttemptAndTask( $attemptId, $taskId );
			$maxScore = (float) ( $existing?->maxScore ?? 1.0 );

			$this->answers->upsert( $attemptId, $taskId, array(
				'score'             => $maxScore,
				'max_score'         => $maxScore,
				'is_correct'        => 1,
				'grader_note'       => $feedback,
				'graded_by_user_id' => get_current_user_id(),
				'graded_at'         => $this->clock->now(),
			) );

			$updated = $this->autoGrade->finalize( $attempt );
			$this->success( $this->gradePayload( $updated ) );
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
		$this->success( $this->gradePayload( $updated ) );
	}

	/**
	 * Утверждение работы (D18): для станций без ручной проверки заданий (ЕГЭ
	 * компьютерный) `AttemptStatus::Graded` наступает сразу при сдаче — этот
	 * статус не значит «учитель посмотрел». Отдельная кнопка «Утвердить работу»
	 * пишет `approved_at`/`approved_by_user_id`, который и открывает ответы
	 * ученику ({@see \Inc\Services\Assessment\AttemptRevealPolicy}).
	 *
	 * Params: attempt_id
	 */
	public function ajaxApproveAttempt(): void {
		$this->authorize( Nonce::GradeAttempt, Capability::ManageLmsTeaching );

		$attemptId = $this->requireInt( 'attempt_id' );

		$attempt = $this->attempts->find( $attemptId );
		if ( ! $attempt ) {
			$this->error( 'Попытка не найдена.' );
			return;
		}

		if ( ! $this->canGrade( $attempt, get_current_user_id() ) ) {
			$this->error( $attempt->isExam() ? 'Нет доступа к этому проведению.' : 'Нет доступа к этой группе.' );
			return;
		}

		// Экзамен утверждает отдельный сервис: версия результата, блокировка участия, событие в outbox (8.5).
		if ( $attempt->isExam() ) {
			$result = $this->examApproval->approve( get_current_user_id(), $attemptId, $this->sanitizeInt( 'result_version' ) );
			if ( ExamApprovalService::STATUS_APPROVED !== $result['status'] ) {
				$reason = (string) ( $result['reason'] ?? '' );
				$this->fail(
					ExamApprovalService::REASON_STALE === $reason ? ErrorCode::ExamStale : ErrorCode::ExamConflict,
					$this->approvalRefusal( $reason )
				);
				return;
			}

			$this->success();
			return;
		}

		$this->attempts->approve( $attemptId, get_current_user_id(), $this->clock->now() );

		$this->success();
	}

	/**
	 * Вправе ли пользователь оценивать и утверждать попытку.
	 *
	 * Экзаменная попытка группы не имеет — решает проведение (его владелец или глобальный доступ, право `ManageExams`).
	 * Попытка курса — фактический преподаватель её группы (T11.9; в период замены оригинал read-only, T5.7).
	 * Попытка без группы и без экзаменного контекста не принадлежит никому — отказ.
	 */
	private function canGrade( AttemptDTO $attempt, int $userId ): bool {
		if ( $attempt->isExam() ) {
			return $this->examConduct->canManageAttempt( $userId, $attempt );
		}

		return null !== $attempt->groupId && $this->guard->canWriteJournal( (int) $attempt->groupId, $userId );
	}

	/**
	 * Занимает версию результата экзаменной попытки перед записью оценки. Отвечает клиенту сам, если оценивать нельзя.
	 *
	 * @return bool true — версия занята, оценку можно писать.
	 */
	private function claimExamVersion( AttemptDTO $attempt ): bool {
		if ( $attempt->isApproved() ) {
			$this->error( 'Работа утверждена: исправьте результат с указанием причины.' );
			return false;
		}

		$expected = $this->sanitizeInt( 'result_version' );
		if ( ! $this->attempts->bumpResultVersion( $attempt->id, $expected ) ) {
			$this->fail( ErrorCode::ExamStale, 'Работу уже изменил другой проверяющий. Обновите страницу.' );
			return false;
		}

		return true;
	}

	/** @return array<string, mixed> */
	private function gradePayload( AttemptDTO $updated ): array {
		$payload = array(
			'attempt_status' => $updated->status->value,
			'total_score'    => $updated->totalScore,
		);
		if ( $updated->isExam() ) {
			// Свежая версия нужна клиенту для следующей оценки в этой же работе без перезагрузки экрана.
			$payload['result_version'] = $this->attempts->find( $updated->id )?->resultVersion ?? $updated->resultVersion;
		}

		return $payload;
	}

	private function approvalRefusal( string $reason ): string {
		return match ( $reason ) {
			ExamApprovalService::REASON_PENDING_REVIEW   => 'Проверка не завершена.',
			ExamApprovalService::REASON_NOT_SUBMITTED    => 'Работа не сдана.',
			ExamApprovalService::REASON_STALE            => 'Работу уже изменил другой проверяющий. Обновите страницу.',
			ExamApprovalService::REASON_ALREADY_APPROVED => 'Работа уже утверждена.',
			ExamApprovalService::REASON_GUEST            => 'Гостю утверждение не требуется.',
			default                                      => 'Не удалось утвердить работу.',
		};
	}
}
