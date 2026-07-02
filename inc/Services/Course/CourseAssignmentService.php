<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Course\AssignmentPolicy;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\CourseManager;
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
	 * Курсы предмета группы для пикера назначения в КТП (Эпик 11 T11.1).
	 *
	 * @return array<int, array{id:int, title:string}>
	 */
	public function coursesForGroup( int $groupId ): array {
		$group = $this->groups->findById( $groupId );
		if ( ! $group ) {
			return array();
		}
		return array_map(
			static fn( $course ): array => array( 'id' => $course->id, 'title' => $course->title ),
			$this->courseManager->getBankBySubject( (string) $group->subject_key )
		);
	}

	/**
	 * Снапшотит уроки курса в программу группы.
	 *
	 * @param AssignmentPolicy $policy Append — дописать; Replace — заменить (удаляет текущие строки).
	 * @return int  Число добавленных строк.
	 */
	public function assign( int $groupId, int $courseId, int $actorUserId, AssignmentPolicy $policy = AssignmentPolicy::Append ): int {
		$group  = $this->groups->findById( $groupId );
		$course = $this->courseManager->get( $courseId );

		if ( ! $group || ! $course ) {
			throw new \InvalidArgumentException( 'Группа или курс не найдены.' );
		}
		if ( $course->subjectKey !== $group->subject_key ) {
			throw new \InvalidArgumentException( 'Курс принадлежит другому предмету.' );
		}

		if ( AssignmentPolicy::Replace === $policy ) {
			$this->groupLessons->deleteAllByGroup( $groupId );
		}

		$position = $this->groupLessons->nextPosition( $groupId );
		$added    = 0;
		foreach ( $course->lessonIds() as $lessonId ) {
			$this->groupLessons->add( new GroupLessonInputDTO(
				groupId         : $groupId,
				lessonId        : $lessonId,
				position        : $position,
				createdByUserId : $actorUserId,
			) );
			$position++;
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
