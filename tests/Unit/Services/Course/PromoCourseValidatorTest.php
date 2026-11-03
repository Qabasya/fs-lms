<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
use Inc\Managers\Course\WorkManager;
use Inc\Managers\Wp\PostManager;
use Inc\Services\Course\OpenCourseValidator;
use Inc\Services\Course\PromoCourseValidator;
use PHPUnit\Framework\TestCase;

class PromoCourseValidatorTest extends TestCase {

	private function course( string $status = 'publish', array $ids = array( 11 ) ): CourseDTO {
		return CourseDTO::fromArray( array(
			'id' => 7, 'subject_key' => 'excel', 'status' => $status,
			'modules' => array( array( 'id' => 'a', 'title' => 'Раздел', 'lesson_ids' => $ids ) ),
		) );
	}

	public function test_published_course_from_any_subject_with_self_checkable_lesson_is_accepted(): void {
		$course = $this->course();
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $course );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array( 'id' => 11, 'status' => 'publish' ) ) );
		$validator = $this->createStub( OpenCourseValidator::class );
		$validator->method( 'lessonProblems' )->willReturn( array() );

		self::assertSame( $course, ( new PromoCourseValidator( $courses, $lessons, $validator, $this->createStub( WorkManager::class ), $this->createStub( PostManager::class ) ) )->requireUsable( 7 ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidCourses' )]
	public function test_missing_draft_or_empty_course_is_rejected( ?CourseDTO $course ): void {
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $course );
		$service = new PromoCourseValidator( $courses, $this->createStub( LessonManager::class ), $this->createStub( OpenCourseValidator::class ), $this->createStub( WorkManager::class ), $this->createStub( PostManager::class ) );
		$this->expectException( \InvalidArgumentException::class );
		$service->requireUsable( 7 );
	}

	public static function invalidCourses(): array {
		return array(
			'не найден' => array( null ),
			'черновик' => array( CourseDTO::fromArray( array( 'id' => 7, 'status' => 'draft' ) ) ),
			'пустой' => array( CourseDTO::fromArray( array( 'id' => 7, 'status' => 'publish' ) ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidLessons' )]
	public function test_unavailable_or_not_self_checkable_lesson_is_rejected( ?LessonDTO $lesson, array $problems ): void {
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $this->course() );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( $lesson );
		$validator = $this->createStub( OpenCourseValidator::class );
		$validator->method( 'lessonProblems' )->willReturn( $problems );
		$this->expectException( \InvalidArgumentException::class );
		( new PromoCourseValidator( $courses, $lessons, $validator, $this->createStub( WorkManager::class ), $this->createStub( PostManager::class ) ) )->requireUsable( 7 );
	}

	public static function invalidLessons(): array {
		return array(
			'удалён' => array( null, array() ),
			'черновик' => array( LessonDTO::fromArray( array( 'id' => 11, 'status' => 'draft' ) ), array() ),
			'ручная проверка' => array( LessonDTO::fromArray( array( 'id' => 11, 'status' => 'publish' ) ), array( 'Нужна ручная проверка' ) ),
		);
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'brokenReferences' )]
	public function test_missing_or_draft_work_or_task_is_rejected( string $type, bool $present, bool $published ): void {
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $this->course() );
		$lesson = LessonDTO::fromArray( array(
			'id' => 11, 'status' => 'publish',
			'steps' => array( array( 'key' => 's1', 'type' => $type, 'payload' => array( 'ref' => 42 ) ) ),
		) );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( $lesson );
		$works = $this->createStub( WorkManager::class );
		if ( 'work' === $type && $present ) {
			$works->method( 'get' )->willReturn( \Inc\DTO\Course\WorkDTO::fromArray( array( 'id' => 42, 'status' => $published ? 'publish' : 'draft' ) ) );
		}
		$posts = $this->createStub( PostManager::class );
		if ( 'task' === $type && $present ) {
			$post = new \WP_Post();
			$post->post_status = $published ? 'publish' : 'draft';
			$posts->method( 'get' )->willReturn( $post );
		}
		$service = new PromoCourseValidator( $courses, $lessons, $this->createStub( OpenCourseValidator::class ), $works, $posts );
		$this->expectException( \InvalidArgumentException::class );
		$service->requireUsable( 7 );
	}

	public static function brokenReferences(): array {
		return array(
			'работа удалена' => array( 'work', false, false ),
			'работа — черновик' => array( 'work', true, false ),
			'задание удалено' => array( 'task', false, false ),
			'задание — черновик' => array( 'task', true, false ),
		);
	}

	public function test_missing_task_inside_published_work_is_rejected(): void {
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $this->course() );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array(
			'id' => 11, 'status' => 'publish',
			'steps' => array( array( 'key' => 's1', 'type' => 'work', 'payload' => array( 'ref' => 42 ) ) ),
		) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( \Inc\DTO\Course\WorkDTO::fromArray( array( 'id' => 42, 'status' => 'publish', 'item_ids' => array( 88 ) ) ) );
		$service = new PromoCourseValidator( $courses, $lessons, $this->createStub( OpenCourseValidator::class ), $works, $this->createStub( PostManager::class ) );
		$this->expectException( \InvalidArgumentException::class );
		$service->requireUsable( 7 );
	}

	public function test_published_work_with_published_task_is_accepted(): void {
		$course = $this->course();
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $course );
		$lessons = $this->createStub( LessonManager::class );
		$lessons->method( 'get' )->willReturn( LessonDTO::fromArray( array(
			'id' => 11, 'status' => 'publish',
			'steps' => array( array( 'key' => 's1', 'type' => 'work', 'payload' => array( 'ref' => 42 ) ) ),
		) ) );
		$works = $this->createStub( WorkManager::class );
		$works->method( 'get' )->willReturn( \Inc\DTO\Course\WorkDTO::fromArray( array( 'id' => 42, 'status' => 'publish', 'item_ids' => array( 88 ) ) ) );
		$post = new \WP_Post();
		$post->post_status = 'publish';
		$post->post_type = 'fs_lms_problems';
		$posts = $this->createStub( PostManager::class );
		$posts->method( 'get' )->willReturn( $post );
		$service = new PromoCourseValidator( $courses, $lessons, $this->createStub( OpenCourseValidator::class ), $works, $posts );
		self::assertSame( $course, $service->requireUsable( 7 ) );
	}

}
