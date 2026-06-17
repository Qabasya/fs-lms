<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\LessonVisibility;
use Inc\Enums\LogEvent;
use Inc\Managers\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

class LessonVisibilityService {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ClockInterface              $clock,
	) {}

	public function setVisibility( int $groupLessonId, string $visibility, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}

		$openedAt = null;

		if ( LessonVisibility::Open->value === $visibility ) {
			// copy-on-publish: заморозить work_ids только при первом открытии.
			if ( ! $row->isPublished() ) {
				$lesson = $this->lessonManager->get( $row->lessonId );
				$this->groupLessons->setWorkIdsSnapshot( $groupLessonId, $lesson?->workIds ?? array() );
				$openedAt = $this->clock->now();
			}
		}

		$this->groupLessons->setVisibility( $groupLessonId, $visibility, $openedAt );

		$event = LessonVisibility::Open->value === $visibility ? LogEvent::LessonPublished : LogEvent::LessonHidden;
		$this->dispatcher->dispatch(
			$event,
			new LearningEvent(
				event       : $event,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
			)
		);
	}

	/** Явно подтянуть новую версию work_ids из эталонного урока (перезаписывает снапшот). */
	public function refreshFromLesson( int $groupLessonId, int $actorUserId ): void {
		$row    = $this->groupLessons->find( $groupLessonId );
		$lesson = $row ? $this->lessonManager->get( $row->lessonId ) : null;
		if ( ! $row || ! $lesson ) {
			throw new \InvalidArgumentException( 'Строка программы или урок не найдены.' );
		}
		$this->groupLessons->setWorkIdsSnapshot( $groupLessonId, $lesson->workIds );

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
}
