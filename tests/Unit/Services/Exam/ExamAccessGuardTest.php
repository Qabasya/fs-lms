<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Services\Exam\ExamAccessGuard;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\DTO\Course\GroupDTO;
use Inc\Enums\Access\Capability;
use PHPUnit\Framework\TestCase;

class ExamAccessGuardTest extends TestCase {

	private GroupsRepository $groups;
	private ExamAccessGuard $guard;

	protected function setUp(): void {
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->guard = new ExamAccessGuard( $this->groups );
	}

	private function grantCap( int $userId, string $cap, bool $grant = true ): void {
		if ( ! isset( $GLOBALS['_test_user_can'] ) ) {
			$GLOBALS['_test_user_can'] = array();
		}
		if ( ! isset( $GLOBALS['_test_user_can'][ $userId ] ) ) {
			$GLOBALS['_test_user_can'][ $userId ] = array();
		}
		$GLOBALS['_test_user_can'][ $userId ][ $cap ] = $grant;
	}

	public function test_teacher_manages_only_own_subjects(): void {
		$userId = 1;
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::Admin->value, false );
		$this->grantCap( $userId, Capability::ManageSubjects->value, false );

		$group1 = new GroupDTO( 1, $userId, 'G1', 'inf_ege', 1, null, false, '' );
		$group2 = new GroupDTO( 2, $userId, 'G2', 'python', 1, null, false, '' );

		$this->groups->method( 'findByTeacherId' )->with( $userId )->willReturn( array( $group1, $group2 ) );

		$this->assertTrue( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
		$this->assertTrue( $this->guard->canManageSubject( $userId, 'python' ) );
		$this->assertFalse( $this->guard->canManageSubject( $userId, 'math' ) );
	}

	public function test_teacher_without_groups_manages_nothing(): void {
		$userId = 1;
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::Admin->value, false );
		$this->grantCap( $userId, Capability::ManageSubjects->value, false );

		$this->groups->method( 'findByTeacherId' )->with( $userId )->willReturn( array() );

		$this->assertFalse( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
		$this->assertFalse( $this->guard->canManageSubject( $userId, 'python' ) );
	}

	public function test_methodist_is_global(): void {
		$userId = 2;
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::ManageSubjects->value, true );
		$this->grantCap( $userId, Capability::Admin->value, false );

		$this->assertTrue( $this->guard->isGlobal( $userId ) );
		$this->assertTrue( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
		$this->assertTrue( $this->guard->canManageSubject( $userId, 'python' ) );
	}

	public function test_admin_is_global(): void {
		$userId = 3;
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::Admin->value, true );

		$this->assertTrue( $this->guard->isGlobal( $userId ) );
		$this->assertTrue( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
	}

	public function test_office_cannot_manage_any_subject(): void {
		$userId = 4;
		$this->grantCap( $userId, Capability::ManageExams->value, false );
		$this->grantCap( $userId, Capability::ManageSubjects->value, true );

		$this->assertFalse( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
	}

	public function test_user_without_manage_exams_is_denied_even_for_own_group(): void {
		$userId = 1;
		$this->grantCap( $userId, Capability::ManageExams->value, false );

		$group = new GroupDTO( 1, $userId, 'G1', 'inf_ege', 1, null, false, '' );
		$this->groups->method( 'findByTeacherId' )->with( $userId )->willReturn( array( $group ) );

		$this->assertFalse( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
	}

	public function test_manageable_subject_keys_intersects_for_teacher(): void {
		$userId = 1;
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::Admin->value, false );
		$this->grantCap( $userId, Capability::ManageSubjects->value, false );

		$group = new GroupDTO( 1, $userId, 'G1', 'inf_ege', 1, null, false, '' );
		$this->groups->method( 'findByTeacherId' )->with( $userId )->willReturn( array( $group ) );

		$allSubjects = array( 'inf_ege', 'python', 'math' );
		$result = $this->guard->manageableSubjectKeys( $userId, $allSubjects );

		$this->assertCount( 1, $result );
		$this->assertContains( 'inf_ege', $result );
	}
}
