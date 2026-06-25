<?php

declare( strict_types=1 );

namespace Inc\Services\Assessment;

use Inc\Managers\Course\LessonManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Course\LessonAccessPolicy;

/**
 * Авторизация доступа к контрольной (Bugfix #5).
 *
 * Контрольная отдаётся публичным singular-пермалинком (плеер экзамена живёт на
 * нём), поэтому доступ нельзя оставлять открытым. Ученик вправе видеть и сдавать
 * контрольную только если он зачислен в группу, чей курс содержит урок со ссылкой
 * на эту контрольную, и сам урок ему доступен (делегируем в {@see LessonAccessPolicy}).
 *
 * @package Inc\Services\Assessment
 */
class AssessmentAccessPolicy {

	public function __construct(
		private readonly StudentRecordRepository $studentRecords,
		private readonly GroupLessonRepository   $groupLessons,
		private readonly LessonManager           $lessons,
		private readonly LessonAccessPolicy      $lessonAccess,
	) {}

	/**
	 * Может ли ученик открыть/сдавать контрольную.
	 *
	 * @param int $studentPersonId Person-id ученика.
	 * @param int $assessmentId    ID поста контрольной.
	 */
	public function canAccess( int $studentPersonId, int $assessmentId ): bool {
		if ( $studentPersonId <= 0 || $assessmentId <= 0 ) {
			return false;
		}

		// Группы ученика (любой статус — финальное решение за LessonAccessPolicy::canRead,
		// который учитывает статус/видимость/даты/ретеншн).
		$groupIds = array();
		foreach ( $this->studentRecords->findByStudent( $studentPersonId ) as $record ) {
			$groupIds[ $record->groupId ] = true;
		}

		foreach ( array_keys( $groupIds ) as $groupId ) {
			foreach ( $this->groupLessons->listByGroup( $groupId ) as $groupLesson ) {
				if ( null === $groupLesson->lessonId ) {
					continue;
				}
				$lesson = $this->lessons->get( $groupLesson->lessonId );
				if ( null === $lesson || ! in_array( $assessmentId, $lesson->assessmentIds(), true ) ) {
					continue;
				}
				if ( $this->lessonAccess->canRead( $studentPersonId, $groupLesson->id ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
