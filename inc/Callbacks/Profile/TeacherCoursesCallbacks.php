<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Profile;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Managers\Course\CourseManager;
use Inc\Enums\Course\LessonKind;
use Inc\Enums\Wp\PageRoutes;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\CoursePreviewService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * AJAX страницы курса преподавателя (Tasks.md З4): программа курса — модули и
 * уроки, как «Мои курсы» ученика, но без прогресса (только признак `held` по дате занятий групп).
 * Автор курсов открывает урок в preview-плеере (`can_preview`), рядовой преподаватель — в режиме
 * преподавателя занятия своей группы (`teacher_url`); доступ к программе — `canViewProgram()`.
 *
 * @package Inc\Callbacks\Profile
 */
class TeacherCoursesCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly CourseManager            $courses,
		private readonly CoursePreviewService     $preview,
		private readonly CoursePreviewAccessGuard $guard,
		private readonly GroupLessonRepository    $groupLessons,
		private readonly GroupsRepository         $groups,
	) {
		parent::__construct();
	}

	/** Params: course_id */
	public function ajaxGetTaughtCourseProgram(): void {
		$this->authorize( Nonce::SaveSchedule, Capability::ManageLmsTeaching );

		$courseId = $this->requireInt( 'course_id' );
		$course   = $this->courses->get( $courseId );

		$userId = get_current_user_id();
		if ( null === $course || ! $this->guard->canViewProgram( $userId, $courseId ) ) {
			$this->error( __( 'Курс недоступен.', 'fs-lms' ) );
		}

		$held    = array_flip( $this->groupLessons->listHeldLessonIdsByCourse( $courseId, current_time( 'mysql' ) ) );
		$own     = $this->ownGroupLessonUrls( $userId, $courseId );
		$modules = $this->preview->program( $course );

		// `held` — урок проведён (по дате) хотя бы в одной группе курса: фронт сворачивает
		// непроведённые, кроме двух ближайших. `teacher_url` — урок в режиме преподавателя
		// в группе этого преподавателя ('' — урок в его группах не заведён).
		foreach ( $modules as $mi => $module ) {
			foreach ( $module['lessons'] as $li => $lesson ) {
				$modules[ $mi ]['lessons'][ $li ]['held']        = isset( $held[ $lesson['id'] ] );
				$modules[ $mi ]['lessons'][ $li ]['teacher_url'] = $own[ $lesson['id'] ] ?? '';
			}
		}

		$this->success(
			array(
				'modules'     => $modules,
				'can_preview' => $this->guard->canPreview( $userId, $courseId ),
			)
		);
	}

	/**
	 * Ссылки на режим преподавателя: урок курса → занятие в первой группе преподавателя,
	 * где оно есть (индивидуальные занятия не в счёт — они привязаны к ученику).
	 *
	 * @return array<int, string> lesson_id => URL плеера
	 */
	private function ownGroupLessonUrls( int $userId, int $courseId ): array {
		$urls = array();
		foreach ( $this->groups->findByTeacherId( $userId ) as $group ) {
			if ( (int) ( $group->course_id ?? 0 ) !== $courseId ) {
				continue;
			}
			foreach ( $this->groupLessons->listByGroup( (int) $group->id ) as $row ) {
				if ( null === $row->lessonId || LessonKind::Group !== $row->kind || isset( $urls[ $row->lessonId ] ) ) {
					continue;
				}
				$urls[ $row->lessonId ] = PageRoutes::LessonPlayer->lessonUrl( $row->groupId, $row->id );
			}
		}

		return $urls;
	}
}
