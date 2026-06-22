<?php

declare( strict_types=1 );

namespace Inc\Services\Group;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonInputDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Course\LessonManager;
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

	/**
	 * Добавляет урок в программу группы вручную.
	 *
	 * Кросс-предметно: урок может принадлежать любому предмету (доп. занятие).
	 * Ручное добавление по умолчанию пиннуется — рукотворная дата не сдвигается reflow.
	 *
	 * @param string|null $label  Необязательный ярлык строки (напр. «Доп. Python #1»).
	 * @param bool        $pinned Зафиксировать строку (по умолчанию true для ручного добавления).
	 */
	public function addLesson( int $groupId, int $lessonId, int $actorUserId, ?string $label = null, bool $pinned = true ): int {
		$group  = $this->groups->findById( $groupId );
		$lesson = $this->lessonManager->get( $lessonId );

		if ( ! $group || ! $lesson ) {
			throw new \InvalidArgumentException( 'Группа или урок не найдены.' );
		}

		$position = $this->groupLessons->nextPosition( $groupId );
		$id       = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $groupId,
			lessonId        : $lessonId,
			position        : $position,
			isPinned        : $pinned,
			createdByUserId : $actorUserId,
			label           : $label,
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

	/**
	 * Дублирует строку программы: тот же урок ещё раз, новой строкой со своей датой.
	 * Кейс «провести один урок дважды на две даты». Дата сбрасывается — ставится заново.
	 *
	 * @return int ID новой строки или 0, если исходная не найдена.
	 */
	public function duplicateLesson( int $groupLessonId, int $actorUserId ): int {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return 0;
		}

		$position = $this->groupLessons->nextPosition( $row->groupId );
		$newId    = $this->groupLessons->add( new GroupLessonInputDTO(
			groupId         : $row->groupId,
			lessonId        : $row->lessonId,
			position        : $position,
			extraWorkIds    : $row->extraWorkIds,
			isPinned        : true,
			teacherUserId   : $row->teacherUserId,
			createdByUserId : $actorUserId,
			label           : $row->label,
		) );

		$lesson = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
		$this->dispatcher->dispatch(
			LogEvent::LessonAddedToProgram,
			new LearningEvent(
				event       : LogEvent::LessonAddedToProgram,
				actorUserId : $actorUserId,
				subjectKey  : $lesson?->subjectKey,
				groupId     : $row->groupId,
				entityType  : 'lesson',
				entityId    : (string) $row->lessonId,
			)
		);

		return $newId;
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

	public function getProgramRow( int $groupLessonId ): ?\Inc\DTO\Course\GroupLessonDTO {
		return $this->groupLessons->find( $groupLessonId );
	}

	/** @return array{row: \Inc\DTO\Course\GroupLessonDTO, topic: string, subject: string}[] */
	public function getProgram( int $groupId ): array {
		$rows   = $this->groupLessons->listByGroup( $groupId );
		$result = array();
		foreach ( $rows as $row ) {
			$lesson   = $row->lessonId ? $this->lessonManager->get( $row->lessonId ) : null;
			$result[] = array(
				'row'     => $row,
				'topic'   => $lesson?->topic ?? '',
				'subject' => $lesson?->subjectKey ?? '',
			);
		}
		return $result;
	}
}
