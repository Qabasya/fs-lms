<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\DTO\Course\GroupLessonDTO;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;

/**
 * Резолвит фактического преподавателя на чтении (Эпик 5, D5).
 *
 * Приоритет: `teacher_user_id` занятия (разовый override) › активная замена
 * (`substitutions` на дату) › `groups.teacher_id`. `groups.teacher_id` НЕ
 * перезаписывается — замена живёт только в таблице замен и гаснет по `valid_to`.
 *
 * @package Inc\Services\Course
 */
class EffectiveTeacherResolver {

	public function __construct(
		private readonly GroupsRepository        $groups,
		private readonly SubstitutionRepository  $substitutions,
	) {}

	/**
	 * Фактический преподаватель группы на дату: активная замена › `groups.teacher_id`.
	 *
	 * @param string $date 'Y-m-d'.
	 */
	public function forGroup( int $groupId, string $date ): ?int {
		$sub = $this->substitutions->findActiveForGroup( $groupId, $date );
		if ( $sub ) {
			return $sub->substituteTeacherId;
		}
		$group = $this->groups->findById( $groupId );
		return $group && null !== $group->teacher_id ? (int) $group->teacher_id : null;
	}

	/**
	 * Фактический преподаватель занятия: разовый `teacher_user_id` › замена › препод группы.
	 * Дата берётся из `scheduled_at` занятия; при отсутствии — переданный fallback.
	 */
	public function forLesson( GroupLessonDTO $lesson, ?string $fallbackDate = null ): ?int {
		if ( null !== $lesson->teacherUserId ) {
			return $lesson->teacherUserId;
		}
		$date = $lesson->scheduledAt ? substr( $lesson->scheduledAt, 0, 10 ) : ( $fallbackDate ?? '' );
		if ( '' === $date ) {
			$group = $this->groups->findById( $lesson->groupId );
			return $group && null !== $group->teacher_id ? (int) $group->teacher_id : null;
		}
		return $this->forGroup( $lesson->groupId, $date );
	}
}
