<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\LogEvent;
use Inc\Managers\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

class ScheduleService {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly GroupsRepository            $groups,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly SessionCalendarService      $calendar,
	) {}

	public function addLesson( int $groupId, int $lessonId, int $actorUserId ): int {
		$group  = $this->groups->findById( $groupId );
		$lesson = $this->lessonManager->get( $lessonId );

		if ( ! $group || ! $lesson ) {
			throw new \InvalidArgumentException( 'Группа или урок не найдены.' );
		}
		if ( $lesson->subjectKey !== $group->subject_key ) {
			throw new \InvalidArgumentException( 'Урок принадлежит другому предмету.' );
		}

		$position = $this->groupLessons->nextPosition( $groupId );
		$id       = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : $position,
			createdByUserId : $actorUserId,
		) );

		$this->dispatcher->dispatch(
			LogEvent::LessonAddedToProgram,
			new LearningEvent(
				event       : LogEvent::LessonAddedToProgram,
				actorUserId : $actorUserId,
				subjectKey  : $lesson->subjectKey,
				groupId     : $groupId,
				entityType  : 'lesson',
				entityId    : (string) $lessonId,
			)
		);

		return $id;
	}

	public function removeLesson( int $groupLessonId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return;
		}
		$this->groupLessons->remove( $groupLessonId );

		$lesson = $this->lessonManager->get( $row->lessonId );
		$this->dispatcher->dispatch(
			LogEvent::LessonRemovedFromProgram,
			new LearningEvent(
				event       : LogEvent::LessonRemovedFromProgram,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				subjectKey  : $lesson?->subjectKey,
				entityType  : 'lesson',
				entityId    : (string) $row->lessonId,
			)
		);
	}

	public function reorder( int $groupId, array $orderedIds, int $actorUserId ): void {
		$this->groupLessons->reorder( $groupId, $orderedIds );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $groupId,
				entityType  : 'group',
				entityId    : (string) $groupId,
				isPublic    : false,
			)
		);
	}

	public function schedule( int $groupLessonId, ?string $scheduledAt, ?int $teacherUserId, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}
		$this->groupLessons->updateSchedule( $groupLessonId, $scheduledAt, $teacherUserId );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
				isPublic    : false,
			)
		);
	}

	public function pin( int $groupLessonId, bool $pinned, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}
		$this->groupLessons->setPinned( $groupLessonId, $pinned );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
				isPublic    : false,
			)
		);
	}

	public function reflow( int $groupId, int $actorUserId ): void {
		$this->calendar->reflow( $groupId );

		$this->dispatcher->dispatch(
			LogEvent::ScheduleChanged,
			new LearningEvent(
				event       : LogEvent::ScheduleChanged,
				actorUserId : $actorUserId,
				groupId     : $groupId,
				entityType  : 'group',
				entityId    : (string) $groupId,
				isPublic    : false,
			)
		);
	}

	/** @return array{row: \Inc\DTO\Course\GroupLessonDTO, topic: string}[] */
	public function getProgram( int $groupId ): array {
		$rows   = $this->groupLessons->listByGroup( $groupId );
		$result = array();
		foreach ( $rows as $row ) {
			$lesson   = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
			$result[] = array(
				'row'   => $row,
				'topic' => $lesson?->topic ?? '',
			);
		}
		return $result;
	}
}
