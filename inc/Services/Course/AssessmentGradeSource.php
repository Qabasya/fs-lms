<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\GradeSourceInterface;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\Enums\Assessment\AttemptStatus;
use Inc\Enums\Course\GradeBadge;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Services\Assessment\SecondaryScoreService;

class AssessmentGradeSource implements GradeSourceInterface {

	public function __construct(
		private readonly AssessmentAttemptRepository $attempts,
		private readonly AssessmentManager           $assessments,
		private readonly SecondaryScoreService       $secondaryScore,
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

			$isPending   = $attempt->status === AttemptStatus::Submitted;
			$displayType = 'score';
			$score       = $attempt->totalScore;

			if ( $isPending ) {
				$displayType = 'pending';
			} elseif ( $assessment && $assessment->kind->needsSecondaryScore() ) {
				// Для ЕГЭ — вторичный балл в ячейке журнала.
				$secondary   = $this->secondaryScore->translate( $attempt->totalScore ?? 0.0, $assessment->scoreMap );
				$score       = null !== $secondary ? (float) $secondary : $attempt->totalScore;
				$displayType = 'score';
			}

			$entries[] = new GradebookEntryDTO(
				studentPersonId : $attempt->studentPersonId,
				groupId         : $attempt->groupId ?? 0,
				sourceType      : 'attempt',
				sourceId        : $attempt->id,
				title           : $title,
				category        : 'assessment',
				score           : $score,
				maxScore        : $attempt->maxScore,
				gradedAt        : $attempt->submittedAt,
				displayType     : $displayType,
				groupLessonId   : $attempt->groupLessonId,
				badge           : $assessment ? GradeBadge::fromAssessmentKind( $assessment->kind ) : null,
				groupKey        : 'assessment:' . $attempt->assessmentId,
			);
		}
		return $entries;
	}
}
