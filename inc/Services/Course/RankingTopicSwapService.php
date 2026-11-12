<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Contracts\ClockInterface;
use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Course\RankingAssignmentOrigin;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;

/** Переносит историю темы вместе с результатами в общей транзакции. */
class RankingTopicSwapService {
	public function __construct(
		private readonly RankingAssignmentSnapshotRepository $snapshots,
		private readonly RankingAssignmentSwapPlan $plan,
		private readonly RankingAssignmentSnapshotService $assignmentSnapshots,
		private readonly LessonManager $lessons,
		private readonly ClockInterface $clock,
	) {}

	/** $write меняет строки и результаты; вызывается под общей блокировкой и транзакцией. */
	public function swap( GroupLessonDTO $first, GroupLessonDTO $second, array $firstLessonIds, array $secondLessonIds, callable $write ): void {
		$this->snapshots->synchronized( function () use ( $first, $second, $firstLessonIds, $secondLessonIds, $write ): void {
			$a = $this->snapshots->forLessonFresh( $first->id );
			$b = $this->snapshots->forLessonFresh( $second->id );
			$a = $this->freezeMissing( $first, $firstLessonIds, $a );
			$b = $this->freezeMissing( $second, $secondLessonIds, $b );
			$visible = LessonVisibility::Hidden->value !== $first->visibility
				|| ( null !== $first->scheduledAt && $first->scheduledAt <= $this->clock->now() && ( ! $second->lessonId || $this->lessons->isPublished( $second->lessonId ) ) );
			if ( $visible ) {
				$pending = array();
				foreach ( $b as $id => $snapshot ) {
					if ( ! empty( $snapshot['publication_unconfirmed'] ) ) {
						throw new \DomainException( 'Дата выдачи работы не подтверждена; перенос невозможен.' );
					}
					if ( ! empty( $snapshot['pending'] ) && ( $snapshot['origin'] ?? '' ) === RankingAssignmentOrigin::Lesson->value ) {
						$pending[] = (int) $id;
					}
				}
				if ( $pending ) {
					$this->assignmentSnapshots->capture( $second->id, $pending, $this->clock->now(), RankingAssignmentOrigin::Lesson );
					$b = $this->snapshots->forLessonFresh( $second->id );
				}
			}
			$firstExtras = $this->extras( $first, $firstLessonIds, $a );
			$secondExtras = $this->extras( $second, $secondLessonIds, $b );
			if ( array_intersect( $firstExtras, $secondLessonIds ) || array_intersect( $secondExtras, $firstLessonIds ) ) {
				throw new \DomainException( 'Работа одновременно относится к теме и дополнительным работам: историю нельзя перенести однозначно.' );
			}
			[ $newFirst, $newSecond ] = $this->plan->build( $a, $b );
			$write( $firstExtras, $secondExtras );
			$this->snapshots->replaceLessons( array( $first->id => $newFirst, $second->id => $newSecond ) );
		} );
	}

	/** Старые выданные работы используют согласованный резерв: дату занятия. */
	private function freezeMissing( GroupLessonDTO $row, array $liveLessonIds, array $snapshots ): array {
		$lessonIds = array_values( array_unique( array_merge( $row->workIdsSnapshot ?? array(), $liveLessonIds ) ) );
		$ids = array_values( array_unique( array_merge( $lessonIds, $row->extraWorkIds ) ) );
		$changed = false;
		$open = LessonVisibility::Hidden->value !== $row->visibility
			|| ( null !== $row->scheduledAt && $row->scheduledAt <= $this->clock->now() && ( ! $row->lessonId || $this->lessons->isPublished( $row->lessonId ) ) );
		foreach ( $ids as $id ) {
			if ( isset( $snapshots[ $id ] ) ) {
				continue;
			}
			$origin = RankingAssignmentOrigin::resolve( (int) $id, $lessonIds, $row->extraWorkIds );
			if ( ! $origin ) {
				throw new \DomainException( 'Работа одновременно относится к теме и дополнительным работам.' );
			}
			if ( $open ) {
				$date = $row->scheduledAt ?? $row->openedAt;
				if ( null === $date ) {
					throw new \DomainException( 'Для старой работы невозможно определить дату выдачи.' );
				}
				$this->assignmentSnapshots->capture( $row->id, array( (int) $id ), $date, $origin );
			} else {
				$this->assignmentSnapshots->markPending( $row->id, array( (int) $id ), $origin );
			}
			$changed = true;
		}
		return $changed ? $this->snapshots->forLessonFresh( $row->id ) : $snapshots;
	}

	private function extras( GroupLessonDTO $row, array $lessonIds, array $snapshots ): array {
		$ids = array_unique( array_merge( $row->workIdsSnapshot ?? array(), $row->extraWorkIds, $lessonIds ) );
		$extras = array();
		foreach ( $ids as $id ) {
			$snapshot = $snapshots[ $id ] ?? null;
			$origin = RankingAssignmentOrigin::tryFrom( (string) ( $snapshot['origin'] ?? '' ) );
			if ( ! $origin ) {
				throw new \DomainException( 'История назначения работы не определена; перенос невозможен.' );
			}
			if ( RankingAssignmentOrigin::Extra === $origin ) {
				if ( in_array( $id, $lessonIds, true ) ) {
					throw new \DomainException( 'Работа одновременно относится к теме и дополнительным работам.' );
				}
				$extras[] = (int) $id;
			}
		}
		return $extras;
	}
}
