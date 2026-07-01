<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\ContentCloneService;
use Inc\Services\Course\CourseBuilderService;
use PHPUnit\Framework\TestCase;

class CourseBuilderServiceTest extends TestCase {

	private CourseBuilderService $service;
	private CourseManager        $courses;
	private LessonManager        $lessons;
	private ContentCloneService  $cloneService;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$posts              = new PostManager();
		$this->courses      = new CourseManager( $posts );
		$this->lessons      = new LessonManager( $posts );
		$this->cloneService = $this->createMock( ContentCloneService::class );
		$this->service      = new CourseBuilderService( $this->courses, $this->lessons, $posts, $this->cloneService );
	}

	private function seedCourse( int $id, string $subject, array $modules ): void {
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_courses', 'post_title' => 'Курс ' . $id ),
			array( 'fs_lms_meta' => array( 'modules' => $modules ) )
		);
	}

	private function seedLesson( int $id, string $subject, array $steps, string $status = 'draft', string $title = '' ): void {
		fs_test_seed_post(
			array( 'ID' => $id, 'post_type' => $subject . '_lessons', 'post_title' => '' !== $title ? $title : ( 'Урок ' . $id ), 'post_status' => $status ),
			array( 'fs_lms_meta' => array( 'steps' => $steps ) )
		);
	}

	public function test_build_tree_resolves_modules_lessons_and_step_titles(): void {
		$this->seedLesson( 10, 'inf', array(
			array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'Привет' ) ),
			array( 'key' => 's2', 'type' => 'work', 'payload' => array( 'ref' => 99 ) ),
		), 'publish' );
		fs_test_seed_post( array( 'ID' => 99, 'post_type' => 'inf_works', 'post_title' => 'ДЗ-1' ) );
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'Введение', 'lesson_ids' => array( 10 ) ) ) );

		$tree = $this->service->buildTree( 1 );

		self::assertNotNull( $tree );
		self::assertSame( 'inf', $tree['subject_key'] );
		self::assertCount( 1, $tree['modules'] );
		self::assertSame( 'Введение', $tree['modules'][0]['title'] );

		$lesson = $tree['modules'][0]['lessons'][0];
		self::assertSame( 10, $lesson['id'] );
		self::assertTrue( $lesson['published'] );
		self::assertSame( 'Привет', $lesson['steps'][0]['title'] );  // inline → payload.title
		self::assertSame( 'ДЗ-1', $lesson['steps'][1]['title'] );    // ref → resolved work title
	}

	public function test_build_tree_null_for_missing_course(): void {
		self::assertNull( $this->service->buildTree( 777 ) );
	}

	public function test_save_structure_persists_titles_order_and_drops_foreign_lessons(): void {
		$this->seedCourse( 1, 'inf', array() );
		$this->seedLesson( 10, 'inf', array() );
		$this->seedLesson( 20, 'math', array() ); // чужой предмет → отбрасывается

		$ok = $this->service->saveStructure( 1, array(
			array( 'id' => 'm1', 'title' => 'Модуль 1', 'lesson_ids' => array( 10, 20 ) ),
		) );

		self::assertTrue( $ok );
		$course = $this->courses->get( 1 );
		self::assertCount( 1, $course->modules );
		self::assertSame( 'Модуль 1', $course->modules[0]->title );
		self::assertSame( array( 10 ), $course->modules[0]->lessonIds );
	}

	public function test_create_lesson_in_module_adds_default_lecture_and_links(): void {
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );

		$node = $this->service->createLessonInModule( 1, 'm1', 'Урок X' );

		self::assertNotNull( $node );
		self::assertSame( 'Урок X', $node['title'] );
		self::assertCount( 1, $node['steps'] );
		self::assertSame( 'text', $node['steps'][0]['type'] );

		$course = $this->courses->get( 1 );
		self::assertSame( array( $node['id'] ), $course->modules[0]->lessonIds );
	}

	public function test_update_lesson_meta_changes_title_and_status(): void {
		$this->seedLesson( 10, 'inf', array(), 'draft', 'Старое' );

		self::assertTrue( $this->service->updateLessonMeta( 10, 'Новое', true ) );

		$lesson = $this->lessons->get( 10 );
		self::assertSame( 'Новое', $lesson->topic );
		self::assertSame( 'publish', $lesson->status );
	}

	public function test_update_lesson_meta_rejects_non_lesson(): void {
		fs_test_seed_post( array( 'ID' => 50, 'post_type' => 'inf_works' ) );
		self::assertFalse( $this->service->updateLessonMeta( 50, 'X', false ) );
	}

	public function test_create_course_returns_retrievable_draft(): void {
		$id = $this->service->createCourse( 'inf', 'Мой курс' );

		self::assertGreaterThan( 0, $id );
		$course = $this->courses->get( $id );
		self::assertNotNull( $course );
		self::assertSame( 'Мой курс', $course->title );
		self::assertSame( 'inf', $course->subjectKey );
	}

	public function test_duplicate_lesson_in_module_inserts_copy_after_original(): void {
		$this->seedLesson( 10, 'inf', array(
			array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'Лекция' ) ),
		), 'publish', 'Урок 1' );
		// Копия, которую «создаёт» замоканный cloneService (с пометкой needs_review).
		$this->seedLesson( 11, 'inf', array(
			array( 'key' => 's1', 'type' => 'text', 'payload' => array( 'title' => 'Лекция', 'needs_review' => true ) ),
		), 'draft', 'Урок 1 (копия)' );
		$this->seedCourse( 1, 'inf', array(
			array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10 ) ),
		) );
		$this->cloneService->method( 'cloneLesson' )->with( 10 )->willReturn( 11 );

		$node = $this->service->duplicateLessonInModule( 1, 'm1', 10 );

		self::assertNotNull( $node );
		self::assertSame( 11, $node['id'] );
		self::assertTrue( $node['steps'][0]['payload']['needs_review'] ); // флаг доезжает до ноды
		self::assertSame( array( 10, 11 ), $this->courses->get( 1 )->modules[0]->lessonIds ); // копия сразу после оригинала
	}

	public function test_duplicate_lesson_in_module_null_when_lesson_not_in_module(): void {
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );
		$this->cloneService->expects( self::never() )->method( 'cloneLesson' ); // без сирот: клон не вызывается

		self::assertNull( $this->service->duplicateLessonInModule( 1, 'm1', 10 ) );
	}
}
