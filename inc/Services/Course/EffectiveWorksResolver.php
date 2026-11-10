<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\DTO\Log\Events\LearningEvent;
use Inc\Enums\Log\LogEvent;
use Inc\Enums\Course\LessonVisibility;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

class EffectiveWorksResolver {

	public function __construct(
		private readonly GroupLessonRepository       $groupLessons,
		private readonly LessonManager               $lessonManager,
		private readonly WorkManager                 $workManager,
		private readonly LogEventDispatcherInterface $dispatcher,
		private readonly ?RankingAssignmentSnapshotService $rankingSnapshots = null,
		private readonly ?LessonVisibilityService $visibility = null,
		private readonly bool $captureRankingAssignments = false,
	) {}

	/**
	 * Вычисляет эффективный набор работ строки программы — объединение:
	 *   work_ids_snapshot (заморожен при публикации, если опубликован)
	 *   ∪ lesson.work_ids (ТЕКУЩИЙ живой набор урока)
	 *   ∪ extra_work_ids  (добавленные вручную)
	 *
	 * Живой набор урока включается всегда: плеер рендерит и гейтит шаги по
	 * живому уроку (LessonPlayerService/LessonGateResolver), поэтому
	 * эффективный набор для гейта сдачи не должен быть строже — иначе работа,
	 * добавленная/обновлённая в уроке уже ПОСЛЕ публикации занятия, видна и
	 * «сдаётся» в UI, но отклоняется сервером («Работа не входит в эффективный
	 * набор урока»). Read-time объединение чинит и уже «застрявшие» строки,
	 * не полагаясь только на write-time syncExtraWorksForOpenOccurrences.
	 * Снапшот при этом сохраняется как надмножество: работа, УДАЛЁННАЯ из
	 * урока после публикации, остаётся сдаваемой (не теряем назначенное).
	 *
	 * @return WorkDTO[]
	 */
	public function resolve( GroupLessonDTO $row ): array {
		$snapshot = $row->isPublished() ? ( $row->workIdsSnapshot ?? array() ) : array();
		$live     = $row->lessonId
			? ( $this->lessonManager->get( $row->lessonId )?->workIds() ?? array() )
			: array();

		$all     = array_unique( array_merge( $snapshot, $live, $row->extraWorkIds ) );
		$works   = array();
		$this->workManager->prime( $all );
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

		if ( $this->captureRankingAssignments && $this->rankingSnapshots && $this->visibility ) {
			$this->rankingSnapshots->synchronized( function () use ( $groupLessonId, $workIds ): void {
				$current = $this->groupLessons->find( $groupLessonId );
				if ( ! $current ) {
					throw new \InvalidArgumentException( 'Строка программы не найдена.' );
				}
				$live = $current->lessonId ? ( $this->lessonManager->get( $current->lessonId )?->workIds() ?? array() ) : array();
				$added = array_values( array_diff( $workIds, $current->workIdsSnapshot ?? array(), $current->extraWorkIds, $live ) );
				if ( LessonVisibility::Hidden->value !== $this->visibility->effectiveVisibility( $current ) ) {
					$this->rankingSnapshots->capture( $groupLessonId, $added );
				} else {
					$this->rankingSnapshots->markPending( $groupLessonId, $added );
				}
				if ( ! $this->groupLessons->setExtraWorkIds( $groupLessonId, $workIds ) ) {
					throw new \RuntimeException( 'Не удалось сохранить дополнительные работы.' );
				}
			} );
		} else {
			$this->groupLessons->setExtraWorkIds( $groupLessonId, $workIds );
		}

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
