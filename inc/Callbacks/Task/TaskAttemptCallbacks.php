<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Task;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX-обработчик для просмотра истории попыток студентов (Этап 6, Phase G).
 * Используется преподавателем в кокпите группы.
 */
class TaskAttemptCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly TaskAttemptRepository $attemptRepository,
	) {}

	/**
	 * Возвращает все попытки по шагу урока, сгруппированные по студентам.
	 * POST: group_lesson_id, step_key.
	 */
	public function ajaxGetTaskAttempts(): void {
		$this->authorize( Nonce::StepSettings, Capability::ManageLMSAssignments );

		$groupLessonId = (int) $this->requireInt( 'group_lesson_id' );
		$stepKey       = $this->requireKey( 'step_key' );

		$attempts  = $this->attemptRepository->listByGroupAndStep( $groupLessonId, $stepKey );
		$byStudent = array();

		foreach ( $attempts as $a ) {
			$sid = $a->studentPersonId;
			if ( ! isset( $byStudent[ $sid ] ) ) {
				$byStudent[ $sid ] = array(
					'student_id'   => $sid,
					'student_name' => get_the_title( $sid ) ?: "Ученик #{$sid}",
					'attempts'     => array(),
				);
			}
			$byStudent[ $sid ]['attempts'][] = array(
				'attempt_number' => $a->attemptNumber,
				'is_correct'     => $a->isCorrect,
				'score'          => $a->score,
				'max_score'      => $a->maxScore,
				'created_at'     => $a->createdAt,
			);
		}

		$this->success( array_values( $byStudent ) );
	}
}
