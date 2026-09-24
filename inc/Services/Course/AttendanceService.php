<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Profile\NotificationService;

/**
 * Class AttendanceService
 *
 * Посещаемость (D4): бинарно присутствовал/отсутствовал. Без баллов и весов.
 *
 * @package Inc\Services\Course
 */
class AttendanceService {

	public function __construct(
		private readonly AttendanceRepository    $attendance,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly StudentRecordRepository $records,
		private readonly NotificationService     $notifications,
	) {}

	/** Отметка одного ученика на занятии. */
	public function mark( int $groupLessonId, int $studentPersonId, bool $present, int $actorUserId ): void {
		$this->attendance->upsert( $groupLessonId, $studentPersonId, $present, $actorUserId );

		$lesson = $this->groupLessons->find( $groupLessonId );
		if ( null === $lesson ) {
			return;
		}

		$this->notifyAttendance(
			$lesson,
			$studentPersonId,
			$this->notifications->studentSnapshotName( $studentPersonId, $lesson->groupId ),
			$present
		);
	}

	/**
	 * Снять отметку ученика (ошибочная отметка). Уже отправленное «пропущено
	 * занятие» по ней отзывается — отметки больше нет.
	 */
	public function clear( int $groupLessonId, int $studentPersonId ): void {
		$this->attendance->delete( $groupLessonId, $studentPersonId );
		$this->retractMissed( $groupLessonId, $studentPersonId );
	}

	/**
	 * Отметить всех активных учеников группы на занятии (паттерн «всем present → флипнуть»).
	 */
	public function markAll( int $groupLessonId, bool $present, int $actorUserId ): void {
		$row = $this->groupLessons->find( $groupLessonId );
		if ( ! $row ) {
			return;
		}
		foreach ( $this->records->findActiveByGroupId( $row->groupId ) as $rec ) {
			$this->attendance->upsert( $groupLessonId, $rec->studentPersonId, $present, $actorUserId );
			$this->notifyAttendance(
				$row,
				$rec->studentPersonId,
				trim( "{$rec->snapshotLastName} {$rec->snapshotFirstName}" ),
				$present
			);
		}
	}

	/** Сколько пропусков подряд — уже повод тревожить родителя («более 2 занятий»). */
	private const ABSENCE_STREAK = 3;

	/**
	 * Уведомления по отметке. Сама Н сразу не уведомляет: «пропущено занятие»
	 * уходит, когда началось следующее занятие, а ученик так и не открыл урок и
	 * не сдал домашнюю работу ({@see \Inc\Services\Profile\NotificationCronService}).
	 * Здесь — только серия пропусков подряд (родителю) и отзыв уже отправленного
	 * «пропущено» при исправлении ошибочной Н.
	 */
	private function notifyAttendance( GroupLessonDTO $lesson, int $studentPersonId, string $studentName, bool $present ): void {
		if ( $present ) {
			$this->retractMissed( $lesson->id, $studentPersonId );
			return;
		}

		$this->notifyAbsenceStreak( $lesson->groupId, $studentPersonId, $studentName );
	}

	/** Отзывает «пропущено занятие» у ученика и родителей. */
	private function retractMissed( int $groupLessonId, int $studentPersonId ): void {
		$studentUserId = $this->notifications->studentUserId( $studentPersonId );
		$this->notifications->retract(
			array_merge(
				$this->notifications->guardianUserIds( $studentPersonId ),
				null !== $studentUserId ? array( $studentUserId ) : array()
			),
			"att:{$groupLessonId}:{$studentPersonId}"
		);
	}

	/**
	 * Родителю — ученик пропустил подряд {@see self::ABSENCE_STREAK} и больше
	 * занятий группы (по последним отметкам в журнале). Одна плитка на серию:
	 * ключ — первое занятие серии, следующие пропуски той же серии не дублируют.
	 */
	private function notifyAbsenceStreak( int $groupId, int $studentPersonId, string $studentName ): void {
		$dates = array();
		foreach ( $this->groupLessons->listByGroup( $groupId ) as $row ) {
			if ( ! $row->kind->isIndividual() && null !== $row->scheduledAt ) {
				$dates[ $row->id ] = $row->scheduledAt;
			}
		}

		$marks = array_values( array_filter(
			$this->attendance->listByStudent( $studentPersonId ),
			static fn( $a ): bool => isset( $dates[ $a->groupLessonId ] )
		) );
		usort( $marks, static fn( $a, $b ): int => strcmp( $dates[ $b->groupLessonId ], $dates[ $a->groupLessonId ] ) );

		$streak = array();
		foreach ( $marks as $mark ) {
			if ( $mark->isPresent ) {
				break;
			}
			$streak[] = $mark->groupLessonId;
		}
		if ( count( $streak ) < self::ABSENCE_STREAK ) {
			return;
		}

		$this->notifications->push(
			$this->notifications->guardianUserIds( $studentPersonId ),
			NotificationType::AbsenceStreak,
			sprintf( 'absent_streak:%d:%d', $studentPersonId, end( $streak ) ),
			array(
				'student_name' => $studentName,
				'count'        => count( $streak ),
				'group_name'   => $this->notifications->groupName( $groupId ),
			),
			(string) add_query_arg( array( 'screen' => 'learner-attendance' ), PageRoutes::UserProfile->url() ),
			$groupId
		);
	}

	/**
	 * Дата первой отметки посещаемости занятия ('Y-m-d H:i:s') или null — когда
	 * занятие реально прошло, если в КТП у него даты нет.
	 */
	public function firstMarkedAt( int $groupLessonId ): ?string {
		$marks = array_map(
			static fn( $a ): string => $a->markedAt,
			$this->attendance->listByGroupLesson( $groupLessonId )
		);

		return array() === $marks ? null : min( $marks );
	}

	/**
	 * Матрица посещаемости группы для журнала.
	 *
	 * @return array<int, array<int, bool>> groupLessonId => [studentPersonId => isPresent]
	 */
	public function matrixForGroup( int $groupId ): array {
		$matrix = array();
		foreach ( $this->attendance->listByGroup( $groupId ) as $a ) {
			$matrix[ $a->groupLessonId ][ $a->studentPersonId ] = $a->isPresent;
		}
		return $matrix;
	}
}
