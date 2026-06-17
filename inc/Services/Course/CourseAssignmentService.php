<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\LogEvent;
use Inc\Managers\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

class CourseAssignmentService {

	public function __construct(
		private readonly CourseManager               $courseManager,
		private readonly GroupsRepository            $groups,
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LogEventDispatcherInterface $dispatcher,
	) {}

	/**
	 * Снапшотит уроки курса в программу группы.
	 *
	 * @param string $policy  'append' — дописать; 'replace' — заменить (удаляет текущие строки).
	 * @return int  Число добавленных строк.
	 */
	public function assign( int $groupId, int $courseId, int $actorUserId, string $policy = 'append' ): int {
		$group  = $this->groups->findById( $groupId );
		$course = $this->courseManager->get( $courseId );

		if ( ! $group || ! $course ) {
			throw new \InvalidArgumentException( 'Группа или курс не найдены.' );
		}
		if ( $course->subjectKey !== $group->subject_key ) {
			throw new \InvalidArgumentException( 'Курс принадлежит другому предмету.' );
		}

		if ( 'replace' === $policy ) {
			$this->groupLessons->deleteAllByGroup( $groupId );
		}

		$added = 0;
		foreach ( $course->lessonIds as $lessonId ) {
			$position = $this->groupLessons->nextPosition( $groupId );
			$this->groupLessons->add( new GroupLessonInputDTO(
				groupId         : $groupId,
				lessonId        : $lessonId,
				position        : $position,
				createdByUserId : $actorUserId,
			) );
			$added++;
		}

		$this->groups->update( $groupId, array( 'course_id' => $courseId ) );

		$this->dispatcher->dispatch(
			LogEvent::CourseAssigned,
			new LearningEvent(
				event       : LogEvent::CourseAssigned,
				actorUserId : $actorUserId,
				subjectKey  : $course->subjectKey,
				groupId     : $groupId,
				entityType  : 'course',
				entityId    : (string) $courseId,
			)
		);

		return $added;
	}
}
