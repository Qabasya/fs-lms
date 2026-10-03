<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Services\Assessment\AttemptRevealPolicy;
use Inc\Services\Course\WorkDetailService;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;

/**
 * Проекция результатов экзамена для ученика и родителя (7.1).
 * Общий renderer задач для «Работ» преподавателя и разбора ученика.
 *
 * @package Inc\Services\Exam
 */
class ExamReviewProjection {

	public function __construct(
		private readonly WorkDetailService $workDetailService,
		private readonly AssessmentAttemptRepository $attemptRepo,
		private readonly ExamParticipationRepository $participationRepo,
		private readonly AttemptRevealPolicy $revealPolicy,
		private readonly ExamFormatRegistry $formatRegistry,
	) {}

	/**
	 * Проекция результатов для режима (read_only или manage) (7.1.4).
	 *
	 * @param int $attemptId ID попытки
	 * @param string $mode 'manage' или 'read_only'
	 *
	 * @return ?array Результаты или null если не найдены
	 */
	public function forViewer( int $attemptId, string $mode ): ?array {
		$attempt = $this->attemptRepo->find( $attemptId );
		if ( ! $attempt || ! $attempt->isExam() ) {
			return null;
		}

		// Получить детали работы
		$details = $this->workDetailService->fromWork( 'attempt', $attemptId );
		if ( ! $details ) {
			return null;
		}

		// Для read_only: проверить раскрытие и зачистить данные если не раскрыто
		if ( 'read_only' === $mode ) {
			$participation = $this->participationRepo->find( $attempt->examParticipationId );
			if ( ! $participation ) {
				return null;
			}

			// Тестовая раскрытие (используется тот же хук как и для работ курса)
			// TODO: Использовать proper assessment с kind, сейчас пропускаем
			$revealed = $attempt->isApproved() || ( 'guest' === $participation->audience && 'in_progress' !== $attempt->status );

			if ( ! $revealed ) {
				return array(
					'revealed' => false,
					'status'   => $attempt->status,
				);
			}

			// Очистить поля оценивания если раскрыто но не утверждено полностью
			$details = $this->filterReadOnly( $details );
		} else {
			// Для manage добавить result_version
			$details['result_version'] = $attempt->resultVersion;
		}

		$details['revealed'] = true;
		return $details;
	}

	/**
	 * Убрать поля оценивания для режима read_only (7.1.4).
	 */
	private function filterReadOnly( array $details ): array {
		// Убрать из корня
		unset( $details['attempt_id'], $details['group_id'], $details['student_name'] );

		// Убрать из каждой задачи поля оценивания
		if ( isset( $details['tasks'] ) && is_array( $details['tasks'] ) ) {
			$details['tasks'] = array_map( function( $task ) {
				unset(
					$task['task_id'],
					$task['manual'],
					$task['criteria'],
					$task['oge_rubric'],
					$task['review_url']
				);
				return $task;
			}, $details['tasks'] );
		}

		return $details;
	}
}
