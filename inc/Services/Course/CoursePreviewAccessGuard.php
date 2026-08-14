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
		if ( $this->isStaffPreviewer( $wpUserId ) ) {
			return true;
		}

		foreach ( $this->groups->findByTeacherId( $wpUserId ) as $g ) {
			if ( (int) ( $g->course_id ?? 0 ) === $courseId ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Может ли пользователь ПРОРЕШИВАТЬ предпросмотр — dry-run проверка ответов
	 * (`PreviewSolveCallbacks`, #5). Гейт по пользователю, а не по курсу: эндпоинты
	 * принимают `ref` задачи/работы/экзамена, а не `course_id`, и связать ref с
	 * курсом дёшево нельзя. Достаточное условие — пользователю доступен хотя бы
	 * один предпросмотр: у сотрудника это так по праву, у преподавателя — если
	 * хотя бы одной его группе назначен курс. У ученика ни того, ни другого нет,
	 * поэтому обойти сохранение штатной сдачи через dry-run он не может.
	 */
	public function canSolvePreview( int $wpUserId ): bool {
		if ( $this->isStaffPreviewer( $wpUserId ) ) {
			return true;
		}

		foreach ( $this->groups->findByTeacherId( $wpUserId ) as $g ) {
			if ( (int) ( $g->course_id ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Сотрудник, которому предпросмотр открыт целиком: админ, платформа, автор
	 * курсов. Публичный, потому что этот же признак нужен доменным гардам, где
	 * «всё остальное» надо сузить до своей области видимости — напр.
	 * {@see \Inc\Services\Assessment\AssessmentAccessPolicy::canPreview()}.
	 */
	public function isStaffPreviewer( int $wpUserId ): bool {
		return user_can( $wpUserId, Capability::Admin->value )
			|| user_can( $wpUserId, Capability::ManageLmsPlatform->value )
			|| user_can( $wpUserId, Capability::AuthorLmsCourses->value );
	}
}
