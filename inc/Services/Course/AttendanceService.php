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

	/**
	 * Уведомление родителя: пропуск занятия (present=false) или отзыв ошибочной
	 * отметки при исправлении на present=true ({@see NotificationService::retract()}).
	 */
	private function notifyAttendance( GroupLessonDTO $lesson, int $studentPersonId, string $studentName, bool $present ): void {
		$dedupeKey   = "att:{$lesson->id}:{$studentPersonId}";
		$guardianIds = $this->notifications->guardianUserIds( $studentPersonId );
		if ( empty( $guardianIds ) ) {
			return;
		}

		if ( $present ) {
			$this->notifications->retract( $guardianIds, $dedupeKey );
			return;
		}

		$this->notifications->push(
			$guardianIds,
			NotificationType::AttendanceMissed,
			$dedupeKey,
			array(
				'student_name' => $studentName,
				'topic'        => $this->notifications->lessonTopic( $lesson ),
				'date'         => $lesson->scheduledAt ? substr( $lesson->scheduledAt, 0, 10 ) : '',
			),
			(string) add_query_arg( array( 'screen' => 'learner-attendance' ), PageRoutes::UserProfile->url() ),
			$lesson->groupId,
			'group_lesson',
			$lesson->id
		);
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
