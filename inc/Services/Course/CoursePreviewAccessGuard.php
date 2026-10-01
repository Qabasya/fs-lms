<?php

declare( strict_types=1 );

namespace Inc\Services\Course;

use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\GroupsRepository;

/**
 * Class CoursePreviewAccessGuard
 *
 * Кто что видит в курсе. ПРЕДПРОСМОТР курса (`/course-preview/`, без группы и ученика) —
 * только у тех, кто пишет курсы: админ, офис, методист (`isStaffPreviewer()`). Рядовой
 * FSTeacher смотрит уроки в режиме преподавателя — через группу (`/lesson/?gid=&gl=`);
 * для него здесь остаётся лишь право видеть ПРОГРАММУ курса, назначенного его группе
 * (`canViewProgram()` — тот же набор, что и `ProfileViewResolver::teacherConfig()`'s
 * `coursesTaught`: «что видно в сайдбаре» == «что можно открыть»).
 *
 * @package Inc\Services\Course
 */
class CoursePreviewAccessGuard {

	public function __construct(
		private readonly GroupsRepository $groups,
	) {}

	/** Открыть предпросмотр курса — только сотрудники с правом авторинга. */
	public function canPreview( int $wpUserId, int $courseId ): bool {
		return $this->isStaffPreviewer( $wpUserId );
	}

	/** Видеть программу курса в «Моих курсах»: автор курсов или преподаватель группы с этим курсом. */
	public function canViewProgram( int $wpUserId, int $courseId ): bool {
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
