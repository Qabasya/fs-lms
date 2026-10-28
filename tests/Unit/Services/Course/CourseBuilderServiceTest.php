<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\ContentCloneService;
use Inc\Services\Course\ContentUsageService;
use Inc\Services\Course\CourseBuilderService;
use Inc\Services\Course\OpenCourseValidator;
use PHPUnit\Framework\TestCase;

class CourseBuilderServiceTest extends TestCase {

	private CourseBuilderService $service;
	private CourseManager        $courses;
	private LessonManager        $lessons;
	private ContentCloneService  $cloneService;
	private ContentUsageService  $usage;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_posts();
		$posts              = new PostManager();
		$this->courses      = new CourseManager( $posts );
		$this->lessons      = new LessonManager( $posts );
		$this->cloneService = $this->createMock( ContentCloneService::class );
		$this->usage        = new ContentUsageService( $posts, $this->createMock( SubjectRepository::class ) );
		$this->service      = new CourseBuilderService(
			$this->courses,
			$this->lessons,
			$posts,
			$this->cloneService,
			$this->createMock( GroupsRepository::class ),
			$this->createMock( OpenCourseValidator::class ),
			$this->usage,
		);
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

	public function test_build_tree_marks_work_and_assessment_steps_shared_with_another_lesson(): void {
		$this->seedLesson( 10, 'inf', array(
			array( 'key' => 's1', 'type' => 'work', 'payload' => array( 'ref' => 99 ) ),
			array( 'key' => 's2', 'type' => 'work', 'payload' => array( 'ref' => 98 ) ),
			array( 'key' => 's3', 'type' => 'assessment', 'payload' => array( 'ref' => 97 ) ),
			array( 'key' => 's4', 'type' => 'task', 'payload' => array( 'ref' => 96 ) ),
		), 'publish' );
		// Второй урок (другого курса) использует работу 99 и контрольную 97; работа 98 — только в первом.
		$this->seedLesson( 11, 'inf', array(
			array( 'key' => 's1', 'type' => 'work', 'payload' => array( 'ref' => 99 ) ),
			array( 'key' => 's2', 'type' => 'assessment', 'payload' => array( 'ref' => 97 ) ),
			array( 'key' => 's3', 'type' => 'task', 'payload' => array( 'ref' => 96 ) ),
		), 'publish' );
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10 ) ) ) );

		$steps = $this->service->buildTree( 1 )['modules'][0]['lessons'][0]['steps'];

		self::assertTrue( $steps[0]['shared'] );                    // работа есть и в уроке 11
		self::assertArrayNotHasKey( 'shared', $steps[1] );          // работа 98 — только здесь
		self::assertTrue( $steps[2]['shared'] );                    // контрольная — так же, как работа
		self::assertArrayNotHasKey( 'shared', $steps[3] );          // задание тренажёра общее всегда — не отмечается
	}

	public function test_build_tree_does_not_mark_work_repeated_inside_one_lesson(): void {
		$this->seedLesson( 10, 'inf', array(
			array( 'key' => 's1', 'type' => 'work', 'payload' => array( 'ref' => 99 ) ),
			array( 'key' => 's2', 'type' => 'work', 'payload' => array( 'ref' => 99 ) ),
		), 'publish' );
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10 ) ) ) );

		$steps = $this->service->buildTree( 1 )['modules'][0]['lessons'][0]['steps'];

		self::assertArrayNotHasKey( 'shared', $steps[0] );
		self::assertArrayNotHasKey( 'shared', $steps[1] );
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

	/** @return array{0: CourseBuilderService, 1: \PHPUnit\Framework\MockObject\MockObject} сервис и PostManager со шпионом setThumbnail */
	private function serviceWithThumbnailSpy(): array {
		$posts = $this->getMockBuilder( PostManager::class )->onlyMethods( array( 'setThumbnail' ) )->getMock();
		$this->usage = new ContentUsageService( $posts, $this->createMock( SubjectRepository::class ) );

		$service = new CourseBuilderService(
			new CourseManager( $posts ),
			new LessonManager( $posts ),
			$posts,
			$this->cloneService,
			$this->createMock( GroupsRepository::class ),
			$this->createMock( OpenCourseValidator::class ),
			$this->usage,
		);

		return array( $service, $posts );
	}

	public function test_update_course_meta_sets_avatar_when_id_given(): void {
		$this->seedCourse( 1, 'inf', array() );
		[ $service, $posts ] = $this->serviceWithThumbnailSpy();
		$posts->expects( $this->once() )->method( 'setThumbnail' )->with( 1, 55 )->willReturn( true );

		self::assertTrue( $service->updateCourseMeta( 1, 'Курс', 'draft', 0, 55 ) );
	}

	public function test_update_course_meta_removes_avatar_on_zero(): void {
		$this->seedCourse( 1, 'inf', array() );
		[ $service, $posts ] = $this->serviceWithThumbnailSpy();
		$posts->expects( $this->once() )->method( 'setThumbnail' )->with( 1, 0 )->willReturn( true );

		self::assertTrue( $service->updateCourseMeta( 1, 'Курс', 'draft', 0, 0 ) );
	}

	public function test_update_course_meta_keeps_avatar_when_not_sent(): void {
		$this->seedCourse( 1, 'inf', array() );
		[ $service, $posts ] = $this->serviceWithThumbnailSpy();
		$posts->expects( $this->never() )->method( 'setThumbnail' );

		self::assertTrue( $service->updateCourseMeta( 1, 'Курс', 'draft' ) );
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

	public function test_create_lesson_in_module_inserts_after_given_lesson(): void {
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10, 20 ) ) ) );

		$node = $this->service->createLessonInModule( 1, 'm1', 'Урок X', 10 );

		self::assertNotNull( $node );
		self::assertSame( array( 10, $node['id'], 20 ), $this->courses->get( 1 )->modules[0]->lessonIds );
	}

	public function test_create_lesson_in_module_appends_when_anchor_is_foreign(): void {
		$this->seedCourse( 1, 'inf', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10, 20 ) ) ) );

		$node = $this->service->createLessonInModule( 1, 'm1', 'Урок X', 999 );

		self::assertNotNull( $node );
		self::assertSame( array( 10, 20, $node['id'] ), $this->courses->get( 1 )->modules[0]->lessonIds );
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

	// ── importLessonsFromSubject ────────────────────────────────────────────

	public function test_import_lessons_inserts_copies_after_anchor_in_order(): void {
		$this->seedLesson( 10, 'oge', array(), 'publish', 'Урок 10' );
		$this->seedLesson( 11, 'oge', array(), 'publish', 'Урок 11' );
		$this->seedLesson( 50, 'py', array(), 'publish', 'Цикл for' );
		$this->seedLesson( 51, 'py', array(), 'publish', 'Условия' );
		// Копии, которые «создаёт» замоканный cloneService.
		$this->seedLesson( 60, 'oge', array(), 'draft', 'Цикл for' );
		$this->seedLesson( 61, 'oge', array(), 'draft', 'Условия' );
		$this->seedCourse( 1, 'oge', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array( 10, 11 ) ) ) );
		$this->cloneService->method( 'importedCopies' )->willReturn( array() );
		$this->cloneService->expects( self::once() )->method( 'importLessons' )->with( array( 50, 51 ), 'oge' )
			->willReturn( array( 'lessons' => array( 50 => 60, 51 => 61 ), 'works' => 2, 'assessments' => 1 ) );

		$result = $this->service->importLessonsFromSubject( 1, 'm1', array( 50, 51 ), 10, false );

		self::assertSame( 'imported', $result['status'] );
		self::assertSame( array( 60, 61 ), array_column( $result['nodes'], 'id' ) );
		self::assertSame( 2, $result['works'] );
		self::assertSame( 1, $result['assessments'] );
		self::assertSame( array( 10, 60, 61, 11 ), $this->courses->get( 1 )->modules[0]->lessonIds );
	}

	/** Повторный импорт без подтверждения ничего не создаёт и называет уже импортированные уроки. */
	public function test_import_lessons_reports_duplicates_and_waits_for_confirmation(): void {
		$this->seedLesson( 50, 'py', array(), 'publish', 'Цикл for' );
		$this->seedCourse( 1, 'oge', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );
		$this->cloneService->method( 'importedCopies' )->willReturn( array( 50 => array( 'id' => 60, 'title' => 'Цикл for' ) ) );
		$this->cloneService->expects( self::never() )->method( 'importLessons' );

		$result = $this->service->importLessonsFromSubject( 1, 'm1', array( 50 ), 0, false );

		self::assertSame( 'duplicates', $result['status'] );
		self::assertSame( array( array( 'id' => 50, 'title' => 'Цикл for' ) ), $result['duplicates'] );
		self::assertSame( array(), $this->courses->get( 1 )->modules[0]->lessonIds );
	}

	public function test_import_lessons_with_force_imports_despite_duplicates(): void {
		$this->seedLesson( 50, 'py', array(), 'publish', 'Цикл for' );
		$this->seedLesson( 62, 'oge', array(), 'draft', 'Цикл for' );
		$this->seedCourse( 1, 'oge', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );
		$this->cloneService->method( 'importedCopies' )->willReturn( array( 50 => array( 'id' => 60, 'title' => 'Цикл for' ) ) );
		$this->cloneService->method( 'importLessons' )->willReturn( array( 'lessons' => array( 50 => 62 ), 'works' => 0, 'assessments' => 0 ) );

		$result = $this->service->importLessonsFromSubject( 1, 'm1', array( 50 ), 0, true );

		self::assertSame( 'imported', $result['status'] );
		self::assertSame( array( 62 ), $this->courses->get( 1 )->modules[0]->lessonIds );
	}

	/** В импорт попадают только настоящие уроки: чужие посты и групповые форки отбрасываются. */
	public function test_import_lessons_ignores_non_lessons_and_group_forks(): void {
		$this->seedLesson( 50, 'py', array(), 'publish', 'Цикл for' );
		fs_test_seed_post( array( 'ID' => 70, 'post_type' => 'py_works', 'post_title' => 'Не урок' ) );
		fs_test_seed_post(
			array( 'ID' => 71, 'post_type' => 'py_lessons', 'post_title' => 'Форк группы' ),
			array( 'fs_lms_meta' => array( 'steps' => array() ), \Inc\Enums\Wp\PostMetaName::ForkedForGroup->value => 5 )
		);
		$this->seedCourse( 1, 'oge', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );
		$this->cloneService->method( 'importedCopies' )->willReturn( array() );
		$this->cloneService->expects( self::once() )->method( 'importLessons' )->with( array( 50 ), 'oge' )
			->willReturn( array( 'lessons' => array(), 'works' => 0, 'assessments' => 0 ) );

		$this->service->importLessonsFromSubject( 1, 'm1', array( 50, 70, 71, 999 ), 0, false );
	}

	public function test_import_lessons_null_for_missing_course_or_nothing_to_import(): void {
		self::assertNull( $this->service->importLessonsFromSubject( 999, 'm1', array( 50 ), 0, false ) );

		$this->seedCourse( 1, 'oge', array( array( 'id' => 'm1', 'title' => 'M', 'lesson_ids' => array() ) ) );
		self::assertNull( $this->service->importLessonsFromSubject( 1, 'm1', array( 999 ), 0, false ) );
	}
}
