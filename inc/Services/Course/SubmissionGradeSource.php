<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\GradeSourceInterface;
use Inc\DTO\Course\GradebookEntryDTO;
use Inc\Enums\Course\GradeBadge;
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

			// В журнал приходят только агрегатные строки сдачи (репозиторий
			// отсекает per-task по task_id IS NULL): score=correct,
			// max_score=total → показываем дробью.
			$displayType = 'fraction';

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
				groupLessonId   : $sub->groupLessonId,
				badge           : GradeBadge::fromWorkType( $sub->workType ),
				// T12.2 (D13): постоянная метка — сдано после дедлайна работы, зафиксированного на момент сдачи.
				isLate          : $sub->isLate(),
				groupKey        : 'work:' . $sub->workId,
			);
		}
		return $entries;
	}
}
