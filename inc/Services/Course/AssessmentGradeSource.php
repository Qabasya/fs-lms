<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\GradeSourceInterface;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;

class AssessmentGradeSource implements GradeSourceInterface {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentManager           $assessments,
	) {}

	public function entriesForGroup( int $groupId ): array {
		return $this->toEntries( $this->attempts->listByGroupForGradebook( $groupId ) );
	}

	public function entriesForStudent( int $studentPersonId ): array {
		return $this->toEntries( $this->attempts->listByStudentForGradebook( $studentPersonId ) );
	}

	private function toEntries( array $attempts ): array {
		$entries = [];
		foreach ( $attempts as $attempt ) {
			$assessment = $this->assessments->get( $attempt->assessmentId );
			$title      = $assessment ? $assessment->title : "Контрольная #{$attempt->assessmentId}";

			$entries[] = new GradebookEntryDTO(
				studentPersonId : $attempt->studentPersonId,
				groupId         : $attempt->groupId ?? 0,
				sourceType      : 'attempt',
				sourceId        : $attempt->id,
				title           : $title,
				category        : 'assessment',
				score           : $attempt->totalScore,
				maxScore        : $attempt->maxScore,
				gradedAt        : $attempt->submittedAt,
			);
		}
		return $entries;
	}
}
