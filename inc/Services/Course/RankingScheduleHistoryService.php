<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Course\RankingAssignmentOrigin;
use Inc\Managers\Course\LessonManager;

/** Сохраняет выдачу до изменения даты занятия под общей транзакцией снимков. */
class RankingScheduleHistoryService {
	public function __construct(
		private readonly LessonManager $lessons,
		private readonly RankingAssignmentSnapshotService $snapshots,
		private readonly ClockInterface $clock,
	) {}

	/** Фиксирует работы нового урока в момент его назначения на занятие. */
	public function captureNewLesson( GroupLessonDTO $row, int $lessonId ): void {
		wp_cache_delete( $lessonId, 'post_meta' );
		$lesson = $this->lessons->get( $lessonId );
		if ( ! $lesson ) {
			throw new \InvalidArgumentException( 'Урок не найден.' );
		}
		$ids = $lesson->workIds();
		if ( ! $ids ) {
			return;
		}
		$now = $this->clock->now();
		$hidden = LessonVisibility::Hidden->value === $row->visibility
			&& ( null === $row->scheduledAt || $row->scheduledAt > $now || 'publish' !== $lesson->status );
		if ( $hidden ) {
			$this->snapshots->markPending( $row->id, $ids, RankingAssignmentOrigin::Lesson );
		} else {
			$this->snapshots->capture( $row->id, $ids, $now, RankingAssignmentOrigin::Lesson );
		}
	}

	/** Вызывать под блокировкой и внутри транзакции снимков. */
	public function captureBeforeMutation( GroupLessonDTO $row ): void {
		$open = $row->scheduledAt ?? $row->openedAt;
		if ( LessonVisibility::Hidden->value === $row->visibility && (
			null === $row->scheduledAt || $row->scheduledAt > $this->clock->now()
			|| ( $row->lessonId && ! $this->lessons->isPublished( $row->lessonId ) )
		) ) {
			return;
		}
		$lessonIds = $row->workIdsSnapshot ?? array();
		if ( $row->lessonId ) {
			wp_cache_delete( $row->lessonId, 'post_meta' );
			$lessonIds = array_merge( $lessonIds, $this->lessons->get( $row->lessonId )?->workIds() ?? array() );
		}
		$lessonIds = array_values( array_unique( $lessonIds ) );
		$extraIds = array_values( array_unique( $row->extraWorkIds ) );
		$groups = array(
			array( array_values( array_diff( $lessonIds, $extraIds ) ), RankingAssignmentOrigin::Lesson ),
			array( array_values( array_diff( $extraIds, $lessonIds ) ), RankingAssignmentOrigin::Extra ),
			array( array_values( array_intersect( $lessonIds, $extraIds ) ), null ),
		);
		$uncertain = null === $open || ( LessonVisibility::Archived->value === $row->visibility && null === $row->openedAt );
		foreach ( $groups as [ $ids, $origin ] ) {
			if ( ! $ids ) {
				continue;
			}
			if ( $uncertain ) {
				$this->snapshots->markPublicationUnconfirmed( $row->id, $ids, $origin );
			} else {
				$this->snapshots->capture( $row->id, $ids, $open, $origin );
			}
		}
	}
}
