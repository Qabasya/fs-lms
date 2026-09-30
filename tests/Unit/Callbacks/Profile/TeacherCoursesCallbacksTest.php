<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Profile;

use Inc\Callbacks\Profile\TeacherCoursesCallbacks;
use Inc\DTO\Course\CourseDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\CoursePreviewAccessGuard;
use Inc\Services\Course\CoursePreviewService;
use PHPUnit\Framework\TestCase;

/**
 * Страница курса преподавателя: программа курса несёт признак `held` — урок
 * проведён (по дате) хотя бы в одной группе курса. По нему фронт сворачивает
 * непроведённые уроки, кроме двух ближайших.
 */
class TeacherCoursesCallbacksTest extends TestCase {

	private CourseManager&\PHPUnit\Framework\MockObject\MockObject $courses;
	private CoursePreviewService&\PHPUnit\Framework\MockObject\MockObject $preview;
	private CoursePreviewAccessGuard&\PHPUnit\Framework\MockObject\MockObject $guard;
	private GroupLessonRepository&\PHPUnit\Framework\MockObject\MockObject $groupLessons;
	private TeacherCoursesCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();
		$GLOBALS['_fs_test_user_id'] = 7;

		$this->courses      = $this->createMock( CourseManager::class );
		$this->preview      = $this->createMock( CoursePreviewService::class );
		$this->guard        = $this->createMock( CoursePreviewAccessGuard::class );
		$this->groupLessons = $this->createMock( GroupLessonRepository::class );
		$this->cb           = new TeacherCoursesCallbacks( $this->courses, $this->preview, $this->guard, $this->groupLessons );
	}

	private function course(): CourseDTO {
		return new CourseDTO( 5, 'math', 'Курс', '', array(), 1, 'publish' );
	}

	public function test_marks_lessons_held_in_any_group(): void {
		$this->courses->method( 'get' )->willReturn( $this->course() );
		$this->guard->method( 'canPreview' )->willReturn( true );
		$this->preview->method( 'program' )->willReturn( array(
			array( 'title' => 'М1', 'lessons' => array(
				array( 'id' => 10, 'num' => 1, 'title' => 'A' ),
				array( 'id' => 11, 'num' => 2, 'title' => 'B' ),
			) ),
			array( 'title' => 'М2', 'lessons' => array(
				array( 'id' => 12, 'num' => 3, 'title' => 'C' ),
			) ),
		) );
		$this->groupLessons->expects( $this->once() )->method( 'listHeldLessonIdsByCourse' )
			->with( 5, $this->isType( 'string' ) )->willReturn( array( 10 ) );
		$_POST = array( 'course_id' => '5' );

		$res = fs_test_capture_json( fn() => $this->cb->ajaxGetTaughtCourseProgram() );

		self::assertTrue( $res->success );
		$modules = $res->payload['modules'];
		self::assertTrue( $modules[0]['lessons'][0]['held'] );
		self::assertFalse( $modules[0]['lessons'][1]['held'] );
		self::assertFalse( $modules[1]['lessons'][0]['held'] );
	}

	public function test_denies_when_preview_not_allowed(): void {
		$this->courses->method( 'get' )->willReturn( $this->course() );
		$this->guard->method( 'canPreview' )->willReturn( false );
		$this->groupLessons->expects( $this->never() )->method( 'listHeldLessonIdsByCourse' );
		$_POST = array( 'course_id' => '5' );

		self::assertFalse( fs_test_capture_json( fn() => $this->cb->ajaxGetTaughtCourseProgram() )->success );
	}
}
