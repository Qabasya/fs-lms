<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Course\ModuleDTO;
use Inc\DTO\Settings\AcademicPeriodDTO;
use Inc\DTO\Subject\SubjectDTO;
use Inc\Enums\Wp\PostMetaName;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\OptionsRepositories\AcademicPeriodRepository;
use Inc\Repositories\OptionsRepositories\SubjectRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\CourseAssignmentService;
use Inc\Services\Course\SelfPacedContentGuard;
use Inc\Services\Course\SelfPacedCourseService;
use PHPUnit\Framework\TestCase;

class SelfPacedCourseServiceTest extends TestCase {
	private CourseManager $courses;
	private PostManager $posts;
	private GroupsRepository $groups;
	private CourseAssignmentService $assignment;
	private SelfPacedCourseService $service;

	protected function setUp(): void {
		fs_test_reset_posts();
		$this->posts = $this->getMockBuilder( PostManager::class )->onlyMethods( array( 'clearCache' ) )->getMock();
		$this->courses = new CourseManager( $this->posts );
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->groups->method( 'withSelfPacedLifecycleLock' )->willReturnCallback( static fn( callable $fn ): mixed => $fn() );
		$this->groups->method( 'withCourseTransaction' )->willReturnCallback( static fn( int $id, callable $fn ): mixed => $fn() );
		$this->assignment = $this->createMock( CourseAssignmentService::class );
		$subjects = $this->createMock( SubjectRepository::class );
		$subjects->method( 'readAll' )->willReturn( array( SubjectDTO::fromArray( array( 'key' => 'inf', 'name' => 'Информатика' ) ) ) );
		$periods = $this->createMock( AcademicPeriodRepository::class );
		$periods->method( 'getCurrentPeriod' )->willReturn( new AcademicPeriodDTO( '2026', '2026', '2026-09-01', '2027-05-31', true ) );
		$this->service = new SelfPacedCourseService( $this->courses, $this->posts, $this->groups,
			$this->createMock( GroupLessonRepository::class ), $this->assignment,
			$this->createMock( SelfPacedContentGuard::class ), $subjects, $periods );
		fs_test_seed_post( array( 'ID' => 15, 'post_type' => 'inf_courses', 'post_author' => 7, 'post_status' => 'draft' ),
			array( PostMetaName::Meta->value => array( 'modules' => array() ) ) );
	}

	public function test_old_client_preserves_access_and_existing_group_without_replacement(): void {
		$this->courses->saveAccess( 15, true, array( 'inf' ), 27 );
		$this->groups->expects( self::never() )->method( 'create' );
		$this->groups->method( 'findBySelfPacedCourse' )->willReturn( (object) array(
			'id' => 27, 'self_paced_course_id' => 15, 'course_id' => 15, 'access_mode' => 'open', 'is_shared' => 0, 'deleted_at' => null,
		) );
		$this->assignment->expects( self::once() )->method( 'assign' )->with( 27, 15, 7, \Inc\Enums\Course\AssignmentPolicy::Append, false );
		self::assertTrue( $this->service->save( 15, null, null, 7, static fn(): bool => true ) );
		$course = $this->courses->get( 15 );
		self::assertTrue( $course->selfPacedEnabled );
		self::assertSame( array( 'inf' ), $course->audienceSubjectKeys );
		self::assertSame( 27, $course->serviceGroupId );
	}

	public function test_unknown_audience_fails_before_any_group_creation(): void {
		$this->groups->expects( self::never() )->method( 'create' );
		$this->expectException( \InvalidArgumentException::class );
		$this->service->save( 15, true, array( 'unknown' ), 7, static fn(): bool => true );
	}

	public function test_different_author_cannot_change_access(): void {
		$this->groups->expects( self::never() )->method( 'create' );
		$this->expectException( \InvalidArgumentException::class );
		$this->service->save( 15, true, array( 'inf' ), 99, static fn(): bool => true );
	}

	public function test_failed_group_creation_stops_before_writing_access(): void {
		$this->groups->method( 'create' )->willReturn( 0 );
		$this->assignment->expects( self::never() )->method( 'assign' );
		try {
			$this->service->save( 15, true, array( 'inf' ), 7, static fn(): bool => true );
			self::fail( 'Expected failed preparation' );
		} catch ( \RuntimeException ) {
			self::assertFalse( $this->courses->get( 15 )->selfPacedEnabled );
		}
	}

	public function test_structure_update_does_not_overwrite_separate_access_meta(): void {
		$this->courses->saveAccess( 15, true, array( 'inf' ), 27 );
		$stale = CourseDTO::fromArray( array( 'id' => 15, 'subject_key' => 'inf', 'title' => 'Новое название' ) );
		self::assertTrue( $this->courses->update( 15, $stale ) );
		self::assertSame( 27, $this->courses->get( 15 )->serviceGroupId );
		self::assertTrue( $this->courses->get( 15 )->selfPacedEnabled );
	}

	public function test_created_course_remains_off_even_if_dto_requests_enabled_or_foreign_group(): void {
		$id = $this->courses->create( 'inf', CourseDTO::fromArray( array(
			'self_paced_enabled' => true, 'service_group_id' => 123, 'audience_subject_keys' => array( 'inf' ),
		) ) );
		$course = $this->courses->get( $id );
		self::assertFalse( $course->selfPacedEnabled );
		self::assertSame( 0, $course->serviceGroupId );
		self::assertSame( array( 'inf' ), $course->audienceSubjectKeys );
		self::assertSame( $course->toArray(), CourseDTO::fromArray( $course->toArray() )->toArray() );
	}
}
