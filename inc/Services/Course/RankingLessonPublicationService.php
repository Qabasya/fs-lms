<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Course\LessonVisibility;
use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;

/** Сохраняет неопределённость, если публикация прервалась после записи WP. */
class RankingLessonPublicationService {
	public function __construct(
		private readonly LessonManager $lessons,
		private readonly GroupLessonRepository $groupLessons,
		private readonly RankingAssignmentSnapshotService $snapshots,
		private readonly ClockInterface $clock,
	) {}

	public function publish( int $lessonId, callable $publish ): bool {
		$result = false;
		$rows = array();
		$startedAt = null;
		$this->snapshots->synchronizedWrite(
			function () use ( $lessonId, &$rows, &$startedAt ): void {
				$lesson = $this->lessons->get( $lessonId );
				if ( ! $lesson || $this->lessons->isPublished( $lessonId ) ) {
					return;
				}
				foreach ( $this->groupLessons->listByLessonId( $lessonId ) as $row ) {
					if ( ! $row->isPublished() || LessonVisibility::Hidden->value !== $row->visibility || null === $row->scheduledAt || $row->scheduledAt > $this->clock->now() ) {
						continue;
					}
					$ids = array_values( array_unique( array_merge( $row->workIdsSnapshot ?? array(), $row->extraWorkIds, $lesson->workIds() ) ) );
					$startedAt ??= $this->clock->now();
					$rows[ $row->id ] = $ids;
					$this->snapshots->markPublicationUnconfirmed( $row->id, $ids );
				}
			},
			function () use ( $lessonId, $publish, &$result, &$rows, &$startedAt ): void {
				$result = $publish();
				if ( $result && $rows && $this->lessons->isPublished( $lessonId ) && $this->samePeriod( $startedAt, $this->clock->now() ) ) {
					$this->snapshots->synchronized( function () use ( $rows ): void {
						foreach ( $rows as $id => $ids ) {
							$this->snapshots->capture( $id, $ids );
						}
					} );
				}
			}
		);
		return $result;
	}

	private function samePeriod( string $first, string $second ): bool {
		$period = static function ( string $date ): string {
			$time = new \DateTimeImmutable( $date );
			$month = (int) $time->format( 'n' );
			$year = (int) $time->format( 'Y' ) + ( 12 === $month ? 1 : 0 );
			return $year . ':' . intdiv( $month % 12, 3 );
		};
		return $period( $first ) === $period( $second );
	}
}
