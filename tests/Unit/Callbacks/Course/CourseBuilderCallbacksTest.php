<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Course;

use Inc\Callbacks\Course\CourseBuilderCallbacks;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\ContentCloneService;
use Inc\Services\Course\CourseBuilderService;
use PHPUnit\Framework\TestCase;

/**
 * Покрытие AJAX-коллбеков конструктора курса. Проверяет, что вход реально читается
 * (регрессия на key-based Sanitizer), делегирование сервису и форму JSON-ответа.
 */
class CourseBuilderCallbacksTest extends TestCase {

	private CourseBuilderCallbacks $callbacks;
	private CourseManager          $courses;
	private LessonManager          $lessons;
	private ContentCloneService    $cloneService;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		fs_test_reset_ajax();
		$posts              = new PostManager();
		$this->courses      = new CourseManager( $posts );
		$this->lessons      = new LessonManager( $posts );
		$this->cloneService = $this->createMock( ContentCloneService::class );
		$this->callbacks    = new CourseBuilderCallbacks(
			new CourseBuilderService( $this->courses, $this->lessons, $posts, $this->cloneService )
		);
	}

	private function seedCourse( int $id, string $subject, array $modules ): void {
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_courses', 'post_title' => 'Курс ' . $id ),
			array( 'fs_lms_meta' => array( 'modules' => $modules ) )
		);
	}

	private function seedLesson( int $id, string $subject, string $status = 'draft', string $title = '' ): void {
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_lessons', 'post_title' => '' !== $title ? $title : ( 'Урок ' . $id ), 'post_status' => $status ),
			array( 'fs_lms_meta' => array( 'steps' => array() ) )
		);
	}

	public function test_create_course_draft_returns_id(): void {
		$_POST = array( 'subject_key' => 'inf', 'title' => 'Новый курс' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCreateCourseDraft() );

		self::assertTrue( $r->success );
		self::assertGreaterThan( 0, $r->payload['id'] );
		self::assertNotNull( $this->courses->get( $r->payload['id'] ) );
	}

	public function test_get_course_builder_returns_tree(): void {
		$this->seedLesson( 10, 'inf' );
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'Введение', 'lesson_ids' => array( 10 ) ) ) );
		$_POST = array( 'course_id' => '1' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxGetCourseBuilder() );

		self::assertTrue( $r->success );
		self::assertSame( 'inf', $r->payload['subject_key'] );
		self::assertCount( 1, $r->payload['modules'] );
		self::assertSame( 10, $r->payload['modules'][0]['lessons'][0]['id'] );
	}

	public function test_get_course_builder_missing_course_errors(): void {
		$_POST = array( 'course_id' => '999' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxGetCourseBuilder() );

		self::assertFalse( $r->success );
	}

	public function test_get_course_builder_missing_param_errors(): void {
		// Регрессия-гард: без course_id requireInt должен отдать error
		// (если бы Sanitizer вызывался значением, вход бы не читался вовсе).
		$_POST = array();

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxGetCourseBuilder() );

		self::assertFalse( $r->success );
	}

	public function test_save_course_structure_persists(): void {
		$this->seedCourse( 1, 'inf', array() );
		$this->seedLesson( 10, 'inf' );
		$_POST = array(
			'course_id' => '1',
			'modules'   => array(
				array( 'id' => 'm1', 'title' => 'Модуль 1', 'lesson_ids' => array( '10' ) ),
			),
		);

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxSaveCourseStructure() );

		self::assertTrue( $r->success );
		$course = $this->courses->get( 1 );
		self::assertSame( 'Модуль 1', $course->modules[0]->title );
		self::assertSame( array( 10 ), $course->modules[0]->lessonIds );
	}

	public function test_create_lesson_in_module_returns_node_and_links(): void {
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );
		$_POST = array( 'course_id' => '1', 'module_id' => 'm1', 'title' => 'Урок X' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCreateLessonInModule() );

		self::assertTrue( $r->success );
		self::assertSame( 'Урок X', $r->payload['title'] );
		self::assertCount( 1, $r->payload['steps'] );
		self::assertSame( array( $r->payload['id'] ), $this->courses->get( 1 )->modules[0]->lessonIds );
	}

	public function test_update_lesson_meta_changes_title_and_status(): void {
		$this->seedLesson( 10, 'inf', 'draft', 'Старое' );
		$_POST = array( 'lesson_id' => '10', 'title' => 'Новое', 'published' => '1' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxUpdateLessonMeta() );

		self::assertTrue( $r->success );
		$lesson = $this->lessons->get( 10 );
		self::assertSame( 'Новое', $lesson->topic );
		self::assertSame( 'publish', $lesson->status );
	}

	public function test_duplicate_lesson_in_module_returns_node_and_links(): void {
		$this->seedLesson( 10, 'inf', 'publish', 'Урок 1' );
		$this->seedLesson( 11, 'inf', 'draft', 'Урок 1 (копия)' ); // «копия» от замоканного cloneService
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10 ) ) ) );
		$this->cloneService->method( 'cloneLesson' )->with( 10 )->willReturn( 11 );
		$_POST = array( 'course_id' => '1', 'module_id' => 'm1', 'lesson_id' => '10' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxDuplicateLessonInModule() );

		self::assertTrue( $r->success );
		self::assertSame( 11, $r->payload['id'] );
		self::assertSame( array( 10, 11 ), $this->courses->get( 1 )->modules[0]->lessonIds );
	}

	public function test_denies_without_capability(): void {
		$GLOBALS['_fs_test_can'] = false;
		$_POST = array( 'subject_key' => 'inf', 'title' => 'X' );

		$r = fs_test_capture_json( fn() => $this->callbacks->ajaxCreateCourseDraft() );

		self::assertFalse( $r->success );
	}
}
