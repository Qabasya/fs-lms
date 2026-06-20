<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\LessonManager;
use Inc\Managers\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

class EffectiveWorksResolver {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly WorkManager                 $workManager,
		private readonly LogEventDispatcherInterface $dispatcher,
	) {}

	/**
	 * Вычисляет эффективный набор работ строки программы:
	 *   опубликован → work_ids_snapshot + extra_work_ids
	 *   не открыт   → lesson.work_ids + extra_work_ids
	 *
	 * @return WorkDTO[]
	 */
	public function resolve( GroupLessonDTO $row ): array {
		$base = $row->isPublished()
			? $row->workIdsSnapshot
			: ( $row->lessonId ? ( $this->lessonManager->get( $row->lessonId )?->workIds() ?? array() ) : array() );

		$all     = array_unique( array_merge( $base, $row->extraWorkIds ) );
		$works   = array();
		foreach ( $all as $workId ) {
			$work = $this->workManager->get( $workId );
			if ( $work ) {
				$works[] = $work;
			}
		}
		return $works;
	}

	public function setExtraWorks( int $groupLessonId, array $workIds, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			throw new \InvalidArgumentException( 'Строка программы не найдена.' );
		}

		$this->groupLessons->setExtraWorkIds( $groupLessonId, $workIds );

		$this->dispatcher->dispatch(
			LogEvent::ExtraWorksChanged,
			new LearningEvent(
				event       : LogEvent::ExtraWorksChanged,
				actorUserId : $actorUserId,
				groupId     : $row->groupId,
				entityType  : 'group_lesson',
				entityId    : (string) $groupLessonId,
				isPublic    : false,
			)
		);
	}
}
