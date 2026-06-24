<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

/**
 * Class ExamLockService
 *
 * Проверяет наличие активной попытки запирающего экзамена у ученика (T7.11).
 * Используется как верхний приоритет в LessonGateResolver — пока идёт экзамен,
 * весь остальной контент заблокирован.
 *
 * @package Inc\Services\Assessment
 */
class ExamLockService {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentManager           $assessments,
	) {}

	/**
	 * Возвращает активную попытку запирающего экзамена или null.
	 * Запирающий — если AssessmentKind::locksContent() === true.
	 */
	public function getActiveLockingAttempt( int $studentPersonId ): ?AttemptDTO {
		$attempt = $this->attempts->findAnyActive( $studentPersonId );
		if ( null === $attempt ) {
			return null;
		}

		$assessment = $this->assessments->get( $attempt->assessmentId );
		if ( null === $assessment ) {
			return null;
		}

		return $assessment->kind->locksContent() ? $attempt : null;
	}

	/** Есть ли у ученика активная попытка запирающего экзамена. */
	public function isLocked( int $studentPersonId ): bool {
		return null !== $this->getActiveLockingAttempt( $studentPersonId );
	}
}
