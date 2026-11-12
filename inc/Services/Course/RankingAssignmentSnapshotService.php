<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Contracts\ClockInterface;
use Inc\Enums\Course\RankingAssignmentOrigin;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;

class RankingAssignmentSnapshotService {
	public function __construct(
		private readonly RankingAssignmentSnapshotRepository $snapshots,
		private readonly WorkManager $works,
		private readonly ClockInterface $clock,
	) {}

	public function synchronized( callable $operation ): void {
		$this->snapshots->synchronized( $operation );
	}

	public function synchronizedWrite( callable $prepare, callable $write ): void {
		$this->snapshots->synchronizedWrite( $prepare, $write );
	}

	/** Дополнительная работа доступна после добавления и открытия занятия. */
	public function issueTime( string $addedAt, string $openedAt ): string {
		return max( $addedAt, $openedAt );
	}

	/** До открытия фиксируется лишь дата добавления: состав ещё может меняться. */
	public function markPending( int $lessonId, array $workIds, ?RankingAssignmentOrigin $origin = null ): void {
		$pending = array();
		$addedAt = $this->clock->now();
		foreach ( $workIds as $id ) {
			wp_cache_delete( (int) $id, 'posts' );
			wp_cache_delete( (int) $id, 'post_meta' );
			if ( ! $this->works->get( (int) $id ) ) {
				throw new \InvalidArgumentException( 'Дополнительная работа не найдена.' );
			}
			$pending[ (int) $id ] = array( 'issued_at' => $addedAt, 'pending' => true );
			if ( $origin ) {
				$pending[ (int) $id ]['origin'] = $origin->value;
			}
		}
		$this->snapshots->addMissing( $lessonId, $pending );
	}

	/** Маркер сохраняется до WP-публикации; без подтверждения максимум неизвестен. */
	public function markPublicationUnconfirmed( int $lessonId, array $workIds, ?RankingAssignmentOrigin $origin = null ): void {
		$pending = array();
		$preparedAt = $this->clock->now();
		foreach ( $workIds as $id ) {
			$work = $this->works->get( (int) $id );
			if ( ! $work ) {
				throw new \RuntimeException( 'Не удалось определить состав публикуемой работы.' );
			}
			$pending[ $work->id ] = array( 'issued_at' => $preparedAt, 'pending' => true, 'publication_unconfirmed' => true, 'source' => $work->workType->value );
			if ( $origin ) {
				$pending[ $work->id ]['origin'] = $origin->value;
			}
		}
		$this->snapshots->addMissing( $lessonId, $pending );
	}

	public function capture( int $lessonId, array $workIds, ?string $openedAt = null, ?RankingAssignmentOrigin $origin = null ): void {
		$snapshots = array();
		$existing = $this->snapshots->forLessonFresh( $lessonId );
		$openedAt ??= $this->clock->now();
		foreach ( $workIds as $id ) {
			$work = $this->works->get( (int) $id );
			if ( ! $work ) {
				throw new \InvalidArgumentException( 'Дополнительная работа не найдена.' );
			}
			$pending = $existing[ $work->id ] ?? array();
			$issuedAt = ! empty( $pending['pending'] ) ? $this->issueTime( $pending['issued_at'], $openedAt ) : $openedAt;
			$snapshots[ $work->id ] = array( 'issued_at' => $issuedAt, 'source' => $work->workType->value, 'task_points' => $work->taskPoints );
			$originValue = $pending['origin'] ?? $origin?->value;
			if ( null !== $originValue ) {
				$snapshots[ $work->id ]['origin'] = $originValue;
			}
		}
		$this->snapshots->addMissing( $lessonId, $snapshots );
	}
}
