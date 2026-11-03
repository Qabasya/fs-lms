<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\LessonDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Course\LessonManager;
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

		self::assertSame( $course, ( new PromoCourseValidator( $courses, $lessons, $validator ) )->requireUsable( 7 ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidCourses' )]
	public function test_missing_draft_or_empty_course_is_rejected( ?CourseDTO $course ): void {
		$courses = $this->createStub( CourseManager::class );
		$courses->method( 'get' )->willReturn( $course );
		$service = new PromoCourseValidator( $courses, $this->createStub( LessonManager::class ), $this->createStub( OpenCourseValidator::class ) );
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
		( new PromoCourseValidator( $courses, $lessons, $validator ) )->requireUsable( 7 );
	}

	public static function invalidLessons(): array {
		return array(
			'удалён' => array( null, array() ),
			'черновик' => array( LessonDTO::fromArray( array( 'id' => 11, 'status' => 'draft' ) ), array() ),
			'ручная проверка' => array( LessonDTO::fromArray( array( 'id' => 11, 'status' => 'publish' ) ), array( 'Нужна ручная проверка' ) ),
		);
	}
}
