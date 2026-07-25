<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Enums\Access\Capability;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Course\CoursePreviewAccessGuard;
use PHPUnit\Framework\TestCase;

/**
 * Доступ к предпросмотру курса: открытие конкретного курса (`canPreview`) и
 * право прорешивать предпросмотр без привязки к курсу (`canSolvePreview`, З2).
 */
class CoursePreviewAccessGuardTest extends TestCase {

	private $groups;
	private CoursePreviewAccessGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_user_can'] = array();

		$this->groups = $this->createMock( GroupsRepository::class );
		$this->guard  = new CoursePreviewAccessGuard( $this->groups );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_user_can'] );
		parent::tearDown();
	}

	/** @param array<int, int> $courseIds course_id каждой группы преподавателя */
	private function teacherGroups( array $courseIds ): void {
		$this->groups->method( 'findByTeacherId' )->willReturn(
			array_map( static fn( int $id ): object => (object) array( 'course_id' => $id ), $courseIds )
		);
	}

	// ── canPreview ──────────────────────────────────────────────────────────

	public function test_staff_can_preview_any_course(): void {
		$GLOBALS['_test_user_can'][7] = array( Capability::AuthorLmsCourses->value => true );
		$this->groups->expects( self::never() )->method( 'findByTeacherId' );

		self::assertTrue( $this->guard->canPreview( 7, 999 ) );
	}

	public function test_teacher_can_preview_only_own_group_course(): void {
		$this->teacherGroups( array( 12 ) );

		self::assertTrue( $this->guard->canPreview( 7, 12 ) );
		self::assertFalse( $this->guard->canPreview( 7, 13 ) );
	}

	// ── canSolvePreview (З2) ────────────────────────────────────────────────

	public function test_staff_can_solve_preview(): void {
		$GLOBALS['_test_user_can'][7] = array( Capability::ManageLmsPlatform->value => true );

		self::assertTrue( $this->guard->canSolvePreview( 7 ) );
	}

	public function test_teacher_with_assigned_course_can_solve_preview(): void {
		$this->teacherGroups( array( 0, 12 ) );

		self::assertTrue( $this->guard->canSolvePreview( 7 ) );
	}

	public function test_teacher_without_assigned_course_cannot_solve_preview(): void {
		// Группы есть, но курс ни одной не назначен — предпросматривать нечего.
		$this->teacherGroups( array( 0 ) );

		self::assertFalse( $this->guard->canSolvePreview( 7 ) );
	}

	public function test_student_cannot_solve_preview(): void {
		// Ни капы, ни групп в роли преподавателя — dry-run закрыт.
		$this->teacherGroups( array() );

		self::assertFalse( $this->guard->canSolvePreview( 7 ) );
	}
}
