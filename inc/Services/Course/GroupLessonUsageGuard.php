<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Repositories\WPDBRepositories\AttendanceRepository;
use Inc\Repositories\WPDBRepositories\LessonProgressRepository;
use Inc\Repositories\WPDBRepositories\SubmissionRepository;
use Inc\Repositories\WPDBRepositories\TaskAttemptRepository;

/**
 * Class GroupLessonUsageGuard
 *
 * Есть ли у строки доставки урока (`group_lessons`) реальная вовлечённость
 * ученика — прогресс шагов, отметка посещаемости, сдача работы или попытка
 * задания. Такую строку нельзя авто-удалять при reconcile (D17.3): за ней стоят
 * данные журнала. Строка без вовлечённости — безопасный «сирота» после того, как
 * урок вышел из курса (например, ошибочно дублированный черновик).
 *
 * Assessment-попытки не ключуются по `group_lesson_id` и здесь не учитываются
 * (см. AssessmentAttemptRepository — иная связь).
 *
 * @package Inc\Services\Course
 */
class GroupLessonUsageGuard {

	public function __construct(
		private readonly LessonProgressRepository $progress,
		private readonly AttendanceRepository     $attendance,
		private readonly SubmissionRepository     $submissions,
		private readonly TaskAttemptRepository    $taskAttempts,
	) {}

	/** Есть ли за строкой доставки данные ученика (нельзя авто-удалять). */
	public function hasEngagement( int $groupLessonId ): bool {
		if ( ! empty( $this->progress->listByGroupLesson( $groupLessonId ) ) ) {
			return true;
		}
		if ( ! empty( $this->attendance->listByGroupLesson( $groupLessonId ) ) ) {
			return true;
		}
		if ( $this->submissions->hasAnyByGroupLesson( $groupLessonId ) ) {
			return true;
		}
		if ( $this->taskAttempts->hasAnyByGroupLesson( $groupLessonId ) ) {
			return true;
		}
		return false;
	}

	/** Безопасно ли удалить осиротевшую строку доставки (нет вовлечённости). */
	public function isSafeToRemove( int $groupLessonId ): bool {
		return ! $this->hasEngagement( $groupLessonId );
	}
}
