<?php

declare( strict_types=1 );

namespace Inc\Services\Profile;

use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Repositories\WPDBRepositories\LearnerRankingRepository;
use Inc\Repositories\OptionsRepositories\RankingAssignmentSnapshotRepository;

/** Собирает задания доступных работ и проверяет их зачёт на момент расчёта. */
class RankingAssignmentsService {
	public function __construct(
		private readonly LearnerRankingRepository $repository,
		private readonly WorkManager $works,
		private readonly LessonManager $lessons,
		private readonly ?RankingAssignmentSnapshotRepository $snapshots = null,
		private readonly ?AssessmentManager $assessments = null,
		private readonly ?ExamFormatRegistry $examFormats = null,
	) {}

	/** @return list<array<string,mixed>> */
	public function tasks( string $subject, string $from, string $until, string $now ): array {
		$credits  = array();
		$creditEvents = array();
		$afterNow = ( new \DateTimeImmutable( $now ) )->modify( '+1 second' )->format( 'Y-m-d H:i:s' );
		$lessonRows = $this->repository->assignmentLessons( $subject );
		$progressIds = array();
		foreach ( $lessonRows as $lesson ) {
			$progressIds[ (int) $lesson['group_lesson_id'] ] = (int) ( $lesson['continued_from_id'] ?? 0 ) ?: (int) $lesson['group_lesson_id'];
		}
		$lessonIds = array_values( array_unique( array_merge( array_map( 'intval', array_column( $lessonRows, 'group_lesson_id' ) ), array_filter( array_map( 'intval', array_column( $lessonRows, 'continued_from_id' ) ) ) ) ) );
		foreach ( $this->repository->workEvents( $subject, '1970-01-01 00:00:00', $afterNow, $lessonIds ) as $event ) {
			$credits[ $this->key( $event ) ] = true;
			$studentId = (int) $event['student_person_id'];
			$eventLessonId = (int) $event['group_lesson_id'];
			$workId = (int) $event['work_id'];
			$taskId = (int) $event['task_id'];
			$previous = $creditEvents[ $studentId ][ $eventLessonId ][ $workId ][ $taskId ] ?? null;
			if ( ! $previous || ( isset( $event['solved_at'], $previous['solved_at'] ) && $event['solved_at'] < $previous['solved_at'] ) ) {
				$creditEvents[ $studentId ][ $eventLessonId ][ $workId ][ $taskId ] = $event;
			}
		}
		foreach ( $this->repository->assessmentEvents( $subject, '1970-01-01 00:00:00', $afterNow ) as $event ) {
			if ( ! empty( $event['group_lesson_id'] ) || ! empty( $event['exam_participation_id'] ) ) {
				if ( empty( $event['group_lesson_id'] ) ) {
					$event['group_lesson_id'] = - (int) $event['exam_participation_id'];
				} else {
					$event['group_lesson_id'] = $progressIds[ (int) $event['group_lesson_id'] ] ?? (int) $event['group_lesson_id'];
				}
				$event['work_id']                = $event['assessment_id'];
				$credits[ $this->key( $event ) ] = true;
			}
		}
		$result = array();
		$workCache = array();
		$lessonCache = array();
		$snapshotCache = array();
		$assessmentCache = array();
		$originalWorks = array();
		$originalAssessments = array();
		foreach ( $lessonRows as $original ) {
			if ( ! empty( $original['continued_from_id'] ) ) {
				continue;
			}
			$originalId = (int) $original['group_lesson_id'];
			$ids = array_merge( $this->ids( $original['work_ids_snapshot'] ), $this->ids( $original['extra_work_ids'] ) );
			$lessonId = (int) ( $original['lesson_id'] ?? 0 );
			if ( $lessonId && ! array_key_exists( $lessonId, $lessonCache ) ) {
				$lessonCache[ $lessonId ] = $this->lessons->get( $lessonId );
			}
			$ids = array_merge( $ids, ( $lessonCache[ $lessonId ] ?? null )?->workIds() ?? array() );
			$snapshotCache[ $originalId ] ??= $this->snapshots?->forLesson( $originalId ) ?? array();
			$ids = array_merge( $ids, array_map( 'intval', array_keys( array_filter( $snapshotCache[ $originalId ], static fn( array $item ): bool => empty( $item['pending'] ) ) ) ) );
			$originalWorks[ (int) $original['student_person_id'] ][ $originalId ] = $ids;
			$originalAssessments[ (int) $original['student_person_id'] ][ $originalId ] = ( $lessonCache[ $lessonId ] ?? null )?->assessmentIds() ?? array();
		}
		foreach ( $lessonRows as $lesson ) {
			$groupLessonId = (int) $lesson['group_lesson_id'];
			$progressId = (int) ( $lesson['continued_from_id'] ?? 0 ) ?: $groupLessonId;
			if ( ! array_key_exists( $groupLessonId, $snapshotCache ) ) {
				$snapshotCache[ $groupLessonId ] = $this->snapshots?->forLesson( $groupLessonId ) ?? array();
			}
			$snapshots = $snapshotCache[ $groupLessonId ];
			$issuedSnapshots = array_filter( $snapshots, static fn( array $snapshot ): bool => empty( $snapshot['pending'] ) && isset( $snapshot['issued_at'] ) );
			$date = $lesson['scheduled_at'] ?? $lesson['opened_at'] ?? null;
			$opened = $lesson['opened_at'] ?? $lesson['scheduled_at'] ?? null;
			$lessonId = (int) ( $lesson['lesson_id'] ?? 0 );
			$snapshotOnly = ! is_string( $date ) || ! is_string( $opened ) || $opened > $now || ( 'hidden' === $lesson['visibility'] && $lessonId && ! $this->lessons->isPublished( $lessonId ) );
			if ( $snapshotOnly ) {
				if ( ! $issuedSnapshots ) {
					continue;
				}
				$date = min( array_column( $issuedSnapshots, 'issued_at' ) );
				$opened = $date;
			}
			$extraIds  = $snapshotOnly ? array() : $this->ids( $lesson['extra_work_ids'] );
			$ids       = $snapshotOnly ? array() : array_merge( $this->ids( $lesson['work_ids_snapshot'] ), $extraIds );
			if ( $lessonId && ! array_key_exists( $lessonId, $lessonCache ) ) {
				$lessonCache[ $lessonId ] = $this->lessons->get( $lessonId );
			}
			$lessonDto = $lessonCache[ $lessonId ] ?? null;
			if ( ! $snapshotOnly ) {
				$ids = array_merge( $ids, $lessonDto?->workIds() ?? array() );
			}
			$ids = array_merge( $ids, array_map( 'intval', array_keys( $issuedSnapshots ) ) );
			if ( $progressId !== $groupLessonId ) {
				$ids = array_diff( $ids, $originalWorks[ (int) $lesson['student_person_id'] ][ $progressId ] ?? array() );
			}
			foreach ( array_unique( $ids ) as $workId ) {
				$snapshot = $snapshots[ $workId ] ?? null;
				if ( ! empty( $snapshot['publication_unconfirmed'] ) ) {
					$known = $creditEvents[ (int) $lesson['student_person_id'] ][ $progressId ][ $workId ] ?? array();
					$dates = array_filter( array_column( $known, 'solved_at' ), 'is_string' );
					$latestIssue = $dates ? min( $dates ) : $now;
					if ( $snapshot['issued_at'] < $until && $snapshot['issued_at'] <= $now && $latestIssue >= $from ) {
						$ambiguous = $snapshot['issued_at'] < $from || $latestIssue >= $until;
						$result[] = array( 'student_person_id' => (int) $lesson['student_person_id'], 'group_lesson_id' => $progressId, 'work_id' => $workId, 'task_id' => 0, 'source' => $snapshot['source'], 'task_points' => 0.0, 'credited' => false, 'max_unknown' => true, 'score_unknown' => $ambiguous && (bool) $known );
						foreach ( $ambiguous ? array() : $known as $taskId => $event ) {
							$result[] = array( 'student_person_id' => (int) $lesson['student_person_id'], 'group_lesson_id' => $progressId, 'work_id' => $workId, 'task_id' => $taskId, 'source' => $snapshot['source'], 'task_points' => (float) ( $event['task_points'] ?? 1 ), 'credited' => true );
						}
					}
					continue;
				}
				$issued   = ! isset( $snapshot['issued_at'] ) ? $date : ( empty( $snapshot['pending'] ) ? $snapshot['issued_at'] : max( $snapshot['issued_at'], $opened ) );
				if ( $issued < $from || $issued >= $until || $issued > $now ) {
					continue;
				}
				if ( ! array_key_exists( $workId, $workCache ) ) {
					$workCache[ $workId ] = $this->works->get( $workId );
				}
				$work = $workCache[ $workId ];
				if ( ( ! $work && ! isset( $snapshot['task_points'], $snapshot['source'] ) ) || ( $work && $work->subjectKey !== $subject ) ) {
					continue;
				}
				$taskPoints = $snapshot['task_points'] ?? $work->taskPoints;
				foreach ( $taskPoints as $taskId => $points ) {
					$row             = array(
						'student_person_id' => (int) $lesson['student_person_id'],
						'group_lesson_id'   => $progressId,
						'work_id'           => $workId,
						'task_id'           => (int) $taskId,
						'source'            => $snapshot['source'] ?? $work->workType->value,
						'task_points'       => (float) $points,
					);
					$row['credited'] = isset( $credits[ $this->key( $row ) ] );
					$result[]        = $row;
				}
			}
			if ( ! $snapshotOnly && $date >= $from && $date < $until && $this->assessments ) {
				$assessmentIds = $lessonDto?->assessmentIds() ?? array();
				if ( $progressId !== $groupLessonId ) {
					$assessmentIds = array_diff( $assessmentIds, $originalAssessments[ (int) $lesson['student_person_id'] ][ $progressId ] ?? array() );
				}
				foreach ( $assessmentIds as $assessmentId ) {
					if ( ! array_key_exists( $assessmentId, $assessmentCache ) ) {
						$assessmentCache[ $assessmentId ] = $this->assessments->get( $assessmentId );
					}
					$assessment = $assessmentCache[ $assessmentId ];
					if ( ! $assessment || $assessment->subjectKey !== $subject ) {
						continue;
					}
					foreach ( array_unique( $assessment->taskIds ) as $taskId ) {
						$row             = array(
							'student_person_id' => (int) $lesson['student_person_id'],
							'group_lesson_id'   => $progressId,
							'work_id'           => $assessmentId,
							'task_id'           => $taskId,
							'source'            => 'assessment',
							'task_points'       => 1.0,
						);
						$row['credited'] = isset( $credits[ $this->key( $row ) ] );
						$result[]        = $row;
					}
				}
			}
		}
		if ( $this->assessments ) {
			foreach ( $this->repository->assignedExams( $subject ) as $exam ) {
				$issued = ( new \DateTimeImmutable( $exam['issued_at_utc'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
				if ( $issued < $from || $issued >= $until || $issued > $now ) {
					continue;
				}
				$assessmentId = (int) $exam['assessment_id'];
				if ( ! array_key_exists( $assessmentId, $assessmentCache ) ) {
					$assessmentCache[ $assessmentId ] = $this->assessments->get( $assessmentId );
				}
				$assessment = $assessmentCache[ $assessmentId ];
				$variantSnapshots = json_decode( (string) ( $exam['variant_snapshot'] ?? '' ), true );
				$variant = is_array( $variantSnapshots ) ? ( $variantSnapshots[ $assessmentId ] ?? null ) : null;
				$kind = AssessmentKind::tryFrom( (string) ( $variant['kind'] ?? '' ) ) ?? $assessment?->kind;
				$maximum = $variant['primary_max'] ?? ( $kind ? $this->examFormats?->for( $kind )?->primaryMax : null ) ?? $exam['max_score'] ?? null;
				if ( null !== $maximum ) {
					$approved = ! empty( $exam['approved_at'] ) && $exam['approved_at'] <= $now && 'graded' === ( $exam['attempt_status'] ?? '' );
					$result[] = array(
						'student_person_id' => (int) $exam['student_person_id'],
						'group_lesson_id' => - (int) $exam['participation_id'],
						'work_id' => $assessmentId,
						'task_id' => 0,
						'source' => 'assessment',
						'task_points' => (float) $maximum,
						'earned_points' => $approved ? (float) round( (float) ( $exam['total_score'] ?? 0 ) ) : 0.0,
					);
					continue;
				}
				$hasSnapshot = is_array( $variant ) && isset( $variant['task_ids'] ) && is_array( $variant['task_ids'] );
				if ( ! $hasSnapshot && ( ! $assessment || $assessment->subjectKey !== $subject ) ) {
					continue;
				}
				$taskIds = $hasSnapshot ? $variant['task_ids'] : $assessment->taskIds;
				foreach ( array_unique( array_map( 'intval', $taskIds ) ) as $taskId ) {
					$row             = array(
						'student_person_id' => (int) $exam['student_person_id'],
						'group_lesson_id'   => - (int) $exam['participation_id'],
						'work_id'           => (int) $exam['assessment_id'],
						'task_id'           => $taskId,
						'source'            => 'assessment',
						'task_points'       => 1.0,
					);
					$row['credited'] = isset( $credits[ $this->key( $row ) ] );
					$result[]        = $row;
				}
			}
		}
		return $result;
	}

	private function ids( mixed $json ): array {
		$ids = is_string( $json ) ? json_decode( $json, true ) : $json;
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	private function key( array $row ): string {
		return (int) $row['student_person_id'] . ':' . (int) $row['group_lesson_id'] . ':' . (int) $row['work_id'] . ':' . (int) $row['task_id'];
	}
}
