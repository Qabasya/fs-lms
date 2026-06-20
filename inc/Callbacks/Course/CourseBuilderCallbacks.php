<?php

declare( strict_types=1 );

namespace Inc\Callbacks\Course;

use Inc\Core\BaseController;
use Inc\Enums\Access\Capability;
use Inc\Enums\Wp\Nonce;
use Inc\Services\Course\CourseBuilderService;
use Inc\Shared\Traits\Authorizer;
use Inc\Shared\Traits\Sanitizer;

/**
 * Class CourseBuilderCallbacks
 *
 * Admin-AJAX обработчики Stepik-style конструктора курса.
 * Шаги уроков сохраняются через существующие LessonCallbacks (SaveLessonSteps/MoveLessonStep).
 *
 * @package Inc\Callbacks\Course
 */
class CourseBuilderCallbacks extends BaseController {

	use Authorizer;
	use Sanitizer;

	public function __construct(
		private readonly CourseBuilderService $builder,
	) {
		parent::__construct();
	}

	/**
	 * Создаёт черновик курса. Params: subject_key, title
	 */
	public function ajaxCreateCourseDraft(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$subject_key = $this->requireKey( 'subject_key' );
		$title       = $this->sanitizeText( 'title' );

		$course_id = $this->builder->createCourse( $subject_key, $title );
		if ( $course_id > 0 ) {
			$this->success( array( 'id' => $course_id ) );
		} else {
			$this->error( 'Не удалось создать курс.' );
		}
	}

	/**
	 * Полное дерево курса для приложения. Params: course_id
	 */
	public function ajaxGetCourseBuilder(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$course_id = $this->requireInt( 'course_id' );
		$tree      = $this->builder->buildTree( $course_id );

		if ( null === $tree ) {
			$this->error( 'Курс не найден.' );
			return;
		}

		$this->success( $tree );
	}

	/**
	 * Сохраняет структуру курса. Params: course_id, modules[] ({id,title,lesson_ids[]})
	 */
	public function ajaxSaveCourseStructure(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$course_id = $this->requireInt( 'course_id' );
		$modules   = $this->sanitizeModules( wp_unslash( $_POST['modules'] ?? array() ) );

		if ( $this->builder->saveStructure( $course_id, $modules ) ) {
			$this->success( array( 'saved' => true ) );
		} else {
			$this->error( 'Не удалось сохранить структуру.' );
		}
	}

	/**
	 * Создаёт урок в модуле. Params: course_id, module_id, title
	 */
	public function ajaxCreateLessonInModule(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$course_id = $this->requireInt( 'course_id' );
		$module_id = $this->requireKey( 'module_id' );
		$title     = $this->sanitizeText( 'title' );

		$node = $this->builder->createLessonInModule( $course_id, $module_id, $title );
		if ( null === $node ) {
			$this->error( 'Не удалось создать урок.' );
			return;
		}

		$this->success( $node );
	}

	/**
	 * Обновляет заголовок/публикацию урока. Params: lesson_id, title, published
	 */
	public function ajaxUpdateLessonMeta(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$lesson_id = $this->requireInt( 'lesson_id' );
		$title     = $this->sanitizeText( 'title' );
		$published = $this->sanitizeBool( 'published' );

		if ( $this->builder->updateLessonMeta( $lesson_id, $title, $published ) ) {
			$this->success( array( 'saved' => true ) );
		} else {
			$this->error( 'Урок не найден.' );
		}
	}

	/**
	 * Обновляет заголовок/публикацию курса. Params: course_id, title, published
	 */
	public function ajaxSaveCourseMeta(): void {
		$this->authorize( Nonce::AuthorCourse, Capability::ManageLMSAssignments );

		$course_id = $this->requireInt( 'course_id' );
		$title     = $this->sanitizeText( 'title' );
		$published = $this->sanitizeBool( 'published' );

		if ( $this->builder->updateCourseMeta( $course_id, $title, $published ) ) {
			$this->success( array( 'saved' => true ) );
		} else {
			$this->error( 'Курс не найден.' );
		}
	}

	/**
	 * Санитайз входного массива модулей (значения, не ключи $_POST).
	 *
	 * @param mixed $raw
	 *
	 * @return array<int, array{id: string, title: string, lesson_ids: int[]}>
	 */
	private function sanitizeModules( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$modules = array();
		foreach ( $raw as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}

			$lesson_ids = array();
			foreach ( (array) ( $module['lesson_ids'] ?? array() ) as $lid ) {
				$id = $this->sanitizeIntValue( $lid );
				if ( $id > 0 ) {
					$lesson_ids[] = $id;
				}
			}

			$modules[] = array(
				'id'         => $this->sanitizeKeyValue( $module['id'] ?? '' ),
				'title'      => $this->sanitizeTextValue( $module['title'] ?? '' ),
				'lesson_ids' => $lesson_ids,
			);
		}

		return $modules;
	}
}
