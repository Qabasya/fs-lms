<?php

declare( strict_types=1 );

namespace Inc\Services\Exam;

use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;

class ExamPlayerIntegrationService {

	private AssessmentAttemptRepository $attemptRepo;
	private ExamSessionRepository $sessionRepo;
	private ExamRegistrationRepository $regRepo;

	public function __construct(
		?AssessmentAttemptRepository $attemptRepo = null,
		?ExamSessionRepository $sessionRepo = null,
		?ExamRegistrationRepository $regRepo = null
	) {
		$this->attemptRepo = $attemptRepo ?? new AssessmentAttemptRepository();
		$this->sessionRepo = $sessionRepo ?? new ExamSessionRepository();
		$this->regRepo = $regRepo ?? new ExamRegistrationRepository();
	}

	public function getExamSessionWindow( int $registrationId ): array {
		$reg = $this->regRepo->find( $registrationId );
		if ( !$reg ) {
			return [];
		}

		$session = $this->sessionRepo->find( $reg->sessionId );
		if ( !$session ) {
			return [];
		}

		return [
			'session_id' => $session->id,
			'scheduled_at' => $session->scheduledAt,
			'planned_end_at' => $session->plannedEndAt,
			'capacity' => $session->capacity,
			'occupied' => $session->occupiedCount,
		];
	}

	public function isExamOpen( int $registrationId ): bool {
		$reg = $this->regRepo->find( $registrationId );
		if ( !$reg || 'confirmed' !== $reg->status ) {
			return false;
		}

		$session = $this->sessionRepo->find( $reg->sessionId );
		if ( !$session ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		return $now >= $session->scheduledAt && $now <= $session->plannedEndAt;
	}

	public function getActiveExamAttempt( int $registrationId ): ?array {
		$reg = $this->regRepo->find( $registrationId );
		if ( !$reg ) {
			return null;
		}

		$attempts = $this->attemptRepo->findByStudentAndAssessment(
			$reg->participationId,
			0
		);

		foreach ( $attempts as $attempt ) {
			if ( 'in_progress' === $attempt->status && $attempt->isExam() ) {
				return [
					'attempt_id' => $attempt->id,
					'started_at' => $attempt->startedAt,
					'deadline_at' => $attempt->deadlineAt,
				];
			}
		}

		return null;
	}
}
