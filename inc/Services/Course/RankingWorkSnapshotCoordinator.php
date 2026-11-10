<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Course\WorkDTO;
use Inc\Enums\Course\LessonVisibility;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

/** Фиксирует состав выданной работы перед её изменением. */
class RankingWorkSnapshotCoordinator {
	public function __construct(
		private readonly GroupLessonRepository $groupLessons,
		private readonly LessonManager $lessons,
		private readonly RankingAssignmentSnapshotRepository $snapshots,
		private readonly ClockInterface $clock,
	) {}

	/** Вызывается внутри общей транзакции снимков. */
	public function captureBeforeChange( WorkDTO $work ): void {
		$now = $this->clock->now();
		foreach ( $this->groupLessons->listBySubject( $work->subjectKey ) as $row ) {
			if ( ! $row->isPublished() || ! $this->isOpen( $row, $now ) ) {
				continue;
			}
			$ids = array_merge( $row->workIdsSnapshot ?? array(), $row->extraWorkIds );
			if ( $row->lessonId ) {
				wp_cache_delete( $row->lessonId, 'post_meta' );
				$ids = array_merge( $ids, $this->lessons->get( $row->lessonId )?->workIds() ?? array() );
			}
			if ( ! in_array( $work->id, $ids, true ) ) {
				continue;
			}
			$existing = $this->snapshots->forLessonFresh( $row->id )[ $work->id ] ?? null;
			if ( is_array( $existing ) && empty( $existing['pending'] ) ) {
				continue;
			}
			$opened = $row->openedAt ?? $row->scheduledAt;
			if ( null === $opened ) {
				throw new \RuntimeException( 'Невозможно определить дату выдачи работы.' );
			}
			$issued = $row->scheduledAt ?? $opened;
			if ( ! empty( $existing['pending'] ) ) {
				$issued = max( $opened, $existing['issued_at'] );
			}
			$this->snapshots->addMissing( $row->id, array( $work->id => array(
				'issued_at' => $issued,
				'source' => $work->workType->value,
				'task_points' => $work->taskPoints,
			) ) );
		}
	}

	private function isOpen( GroupLessonDTO $row, string $now ): bool {
		if ( LessonVisibility::Open->value === $row->visibility ) {
			return true;
		}
		if ( LessonVisibility::Archived->value === $row->visibility ) {
			return null !== $row->openedAt;
		}
		return null !== $row->scheduledAt && $row->scheduledAt <= $now
			&& ( ! $row->lessonId || $this->lessons->isPublished( $row->lessonId ) );
	}
}
