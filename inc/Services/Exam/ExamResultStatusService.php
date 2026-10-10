<?php
/**
 * Единое вычисление состояния работы для доски, проверки и результатов.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamResultStatus;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;

/** Единое вычисление состояния работы для доски, проверки и результатов. */
class ExamResultStatusService {

	/** Получает проверку незавершённых ответов без загрузки полного разбора. */
	public function __construct(
		private readonly AssessmentAnswerRepository $answers,
	) {}

	/**
	 * Состояние результата строки.
	 *
	 * Гостю утверждение не требуется: готовая работа для него равнозначна утверждённой («Результат выдан»).
	 */
	public function status( ?AttemptDTO $attempt, ExamAudience $audience ): ExamResultStatus {
		if ( null === $attempt || AttemptStatus::InProgress === $attempt->status ) {
			return ExamResultStatus::None;
		}
		if ( $this->answers->hasPendingAnswers( $attempt->id ) ) {
			return ExamResultStatus::PendingReview;
		}
		if ( $attempt->isApproved() || ExamAudience::Guest === $audience ) {
			return ExamResultStatus::Approved;
		}

		return ExamResultStatus::Ready;
	}
}
