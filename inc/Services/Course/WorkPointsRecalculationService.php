<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\SubmissionDTO;
use Inc\Enums\Course\SubmissionStatus;
use Inc\Enums\Course\WorkType;
use Inc\Managers\Course\WorkManager;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Shared\Traits\TransactionRunner;

/** Разовое обновление баллов сданных работ без повторной проверки ответов. */
class WorkPointsRecalculationService {
	use TransactionRunner;

	public function __construct( private readonly SubmissionRepository $submissions, private readonly WorkManager $works ) {}

	/** @return array{last_id:int,scanned:int,changed:int,skipped:int} */
	public function runBatch( int $afterId, int $limit, bool $dryRun ): array {
		$result = array( 'last_id' => $afterId, 'scanned' => 0, 'changed' => 0, 'skipped' => 0 );
		foreach ( $this->submissions->listWorkAggregatesAfter( $afterId, $limit ) as $aggregate ) {
			++$result['scanned'];
			$result['last_id'] = $aggregate->id;
			$change = $this->plan( $aggregate );
			if ( null === $change ) {
				++$result['skipped'];
				continue;
			}
			if ( array() === $change ) {
				continue;
			}
			++$result['changed'];
			if ( ! $dryRun ) {
				$this->inTransaction( function () use ( $change ): void {
					foreach ( $change as $id => $data ) {
						if ( ! $this->submissions->update( $id, $data ) ) {
							throw new \RuntimeException( 'Не удалось обновить сдачу ' . $id );
						}
					}
				} );
			}
		}
		return $result;
	}

	/** null — недостаточно данных; пустой массив — пересчёт ничего не изменит. */
	private function plan( SubmissionDTO $aggregate ): ?array {
		$work = $this->works->get( $aggregate->workId );
		$snapshot = json_decode( (string) $aggregate->answerText, true );
		$rows = $this->submissions->listPerTaskByStudentWorkLesson( $aggregate->studentPersonId, $aggregate->groupLessonId, $aggregate->workId );
		if ( null === $work || WorkType::Survey === $work->workType || ! is_array( $snapshot ) || array() === $rows || $aggregate->isCredited() ) {
			return null;
		}
		$updates = array();
		$total = 0.0;
		$maximum = 0.0;
		$rowIds = array_map( static fn( SubmissionDTO $row ): int => (int) $row->taskId, $rows );
		if ( array_diff( array_map( 'intval', array_keys( $snapshot ) ), $rowIds ) ) {
			return null;
		}
		foreach ( $rows as $row ) {
			$id = $row->taskId;
			if ( null === $id || ! in_array( $id, $work->itemIds, true ) || ! isset( $snapshot[ $id ] ) || ! is_array( $snapshot[ $id ] ) ) {
				return null;
			}
			$score = $row->score ?? 0.0;
			$max = $row->maxScore ?? (float) ( $snapshot[ $id ]['maxScore'] ?? 0 );
			if ( null === $row->gradedByUserId && SubmissionStatus::Graded === $row->status ) {
				$verdict = $snapshot[ $id ]['verdict'] ?? null;
				if ( ! in_array( $verdict, array( 'correct', 'incorrect' ), true ) ) {
					return null;
				}
				$max = $work->pointsForTask( $id );
				$score = 'correct' === $verdict ? $max : 0.0;
				if ( $row->score !== $score || $row->maxScore !== $max ) {
					$updates[ $row->id ] = array( 'score' => $score, 'max_score' => $max );
				}
				$snapshot[ $id ]['score'] = $score;
				$snapshot[ $id ]['maxScore'] = $max;
			}
			$total += $score;
			$maximum += $max;
		}
		if ( array() === $updates ) {
			return array();
		}
		$updates[ $aggregate->id ] = array( 'answer_text' => wp_json_encode( $snapshot ), 'score' => $total, 'max_score' => $maximum );
		return $updates;
	}
}
