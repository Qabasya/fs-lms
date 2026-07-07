<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Class CoursePreviewAccessGuard
 *
 * Кто может открыть preview-плеер курса (Фаза 5, D3/D4): офис/методист/админ —
 * всегда; рядовой FSTeacher — только для курсов, назначенных хотя бы одной из
 * его групп (тот же набор, что и `ProfileViewResolver::teacherConfig()`'s
 * `coursesTaught` — «что видно в сайдбаре» == «что можно открыть»).
 *
 * @package Inc\Services\Course
 */
class CoursePreviewAccessGuard {

	public function __construct(
		private readonly GroupsRepository $groups,
	) {}

	public function canPreview( int $wpUserId, int $courseId ): bool {
		if ( user_can( $wpUserId, Capability::Admin->value )
			|| user_can( $wpUserId, Capability::ManageLmsPlatform->value )
			|| user_can( $wpUserId, Capability::AuthorLmsCourses->value ) ) {
			return true;
		}

		foreach ( $this->groups->findByTeacherId( $wpUserId ) as $g ) {
			if ( (int) ( $g->course_id ?? 0 ) === $courseId ) {
				return true;
			}
		}

		return false;
	}
}
