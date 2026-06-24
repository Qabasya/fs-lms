<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\GradeSourceInterface;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;

class SubmissionGradeSource implements GradeSourceInterface {

	public function __construct(
		private readonly SubmissionRepository  $submissions,
		private readonly GroupLessonRepository $groupLessons,
		private readonly LessonManager         $lessonManager,
	) {}

	public function entriesForGroup( int $groupId ): array {
		return $this->toEntries( $this->submissions->listForGradebookByGroup( $groupId ) );
	}

	public function entriesForStudent( int $studentPersonId ): array {
		return $this->toEntries( $this->submissions->listForGradebookByStudent( $studentPersonId ) );
	}

	private function toEntries( array $submissions ): array {
		$entries = array();
		foreach ( $submissions as $sub ) {
			$gl     = $this->groupLessons->find( $sub->groupLessonId );
			$lesson = ( $gl && $gl->lessonId ) ? $this->lessonManager->get( $gl->lessonId ) : null;
			$title  = $lesson ? $lesson->topic : "Работа #{$sub->workId}";

			// Агрегатная строка пакетной сдачи: score=correct, max_score=total → дробь.
			// Per-task строки (task_id != null) не попадают в журнал отдельными строками.
			$isBatchAggregate = null === $sub->taskId;
			$displayType      = $isBatchAggregate ? 'fraction' : 'score';

			$entries[] = new GradebookEntryDTO(
				studentPersonId : $sub->studentPersonId,
				groupId         : $gl ? $gl->groupId : 0,
				sourceType      : 'submission',
				sourceId        : $sub->id,
				title           : $title,
				category        : $sub->workType->value,
				score           : $sub->score,
				maxScore        : $sub->maxScore,
				gradedAt        : $sub->gradedAt,
				displayType     : $displayType,
			);
		}
		return $entries;
	}
}
