<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use DateTimeImmutable;
use Inc\Contracts\ClockInterface;
use Inc\Repositories\OptionsRepositories\RankingSnapshotRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Repositories\WPDBRepositories\RankingSnapshotTransaction;

/** Снимок создаётся тиком, а не при открытии рейтинга. */
class RankingSnapshotService {
	public function __construct(
		private readonly RankingSnapshotRepository $snapshots,
		private readonly SubjectRepository $subjects,
		private readonly LearnerRankingRepository $roster,
		private readonly RankingCalculationService $ranking,
		private readonly RankingSnapshotBuilder $builder,
		private readonly RankingSnapshotPeriod $interval,
		private readonly ClockInterface $clock,
		private readonly RankingSnapshotTransaction $transaction,
	) {}

	public function capture(): void {
		$start = $this->clock->now();
		$tz = wp_timezone();
		$instant = new DateTimeImmutable( $start, $tz );
		if ( ! $this->interval->inCaptureWindow( $instant, $tz ) ) {
			return;
		}
		$boundary = $this->interval->start( $instant, $tz )->format( 'Y-m-d H:i:s' );
		$existing = $this->snapshots->read();
		if ( ( $existing['interval'] ?? null ) === RankingSnapshotPeriod::INTERVAL && ( $existing['boundary'] ?? null ) === $boundary
			&& is_string( $existing['captured_at'] ?? null ) ) {
			try {
				$existingTime = new DateTimeImmutable( $existing['captured_at'], $tz );
				if ( $this->interval->inCaptureWindow( $existingTime, $tz ) && $this->interval->start( $existingTime, $tz )->format( 'Y-m-d H:i:s' ) === $boundary ) {
					return;
				}
			} catch ( \Exception $e ) {
				// Повреждённый снимок можно восстановить, пока открыто окно.
			}
		}
		$season = $this->ranking->period( $start );
		$data = $this->transaction->read( function () use ( $start, $season ): array {
			$result = array();
			foreach ( $this->subjects->readActive() as $subject ) {
				$scores = $this->ranking->scoresForSubject( $subject->key, $start, $season );
				$result[ $subject->key ] = $this->builder->build( $scores, $this->roster->roster( $subject->key ) );
			}
			return $result;
		} );
		$finished = $this->clock->now();
		// Не публиковать неоднородный срез, если граница изменилась во время расчёта.
		$finishedAt = new DateTimeImmutable( $finished, $tz );
		if ( $this->interval->start( $finishedAt, $tz )->format( 'Y-m-d H:i:s' ) !== $boundary || ! $this->interval->inCaptureWindow( $finishedAt, $tz ) ) {
			return;
		}
		$this->snapshots->save( array(
			'interval' => RankingSnapshotPeriod::INTERVAL,
			'boundary' => $boundary,
			'captured_at' => $start,
			'season' => $season['from'],
			'subjects' => $data,
		) );
	}

	/** @return array{direction:string, baseline_at:string}|null */
	public function trend( string $subject, int $groupId, int $personId, int $rank, string $season, string $now ): ?array {
		$snapshot = $this->snapshots->read();
		$tz = wp_timezone();
		$boundary = $this->interval->start( new DateTimeImmutable( $now, $tz ), $tz )->format( 'Y-m-d H:i:s' );
		if ( $boundary === $season ) {
			return null;
		}
		if ( ( $snapshot['interval'] ?? null ) !== RankingSnapshotPeriod::INTERVAL
			|| ( $snapshot['boundary'] ?? null ) !== $boundary
			|| ( $snapshot['season'] ?? null ) !== $season
			|| ! is_string( $snapshot['captured_at'] ?? null )
			|| $snapshot['captured_at'] > $now ) {
			return null;
		}
		try {
			$captured = new DateTimeImmutable( $snapshot['captured_at'], $tz );
		} catch ( \Exception $e ) {
			return null;
		}
		if ( ! $this->interval->inCaptureWindow( $captured, $tz ) || $this->interval->start( $captured, $tz )->format( 'Y-m-d H:i:s' ) !== $boundary ) {
			return null;
		}
		$previous = $snapshot['subjects'][ $subject ][ $groupId ][ $personId ] ?? null;
		if ( ! is_int( $previous ) || $previous <= 0 || $rank <= 0 || $previous === $rank ) {
			return null;
		}
		return array( 'direction' => $rank < $previous ? 'up' : 'down', 'baseline_at' => $snapshot['captured_at'] );
	}
}
