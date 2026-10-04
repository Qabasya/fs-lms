<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Services\Exam\ExamAccessGuard;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Enums\Access\Capability;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

class ExamAccessGuardTest extends TestCase {

	use ExamFixtures;

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

		$group1 = (object) array( 'id' => 1, 'teacher_id' => $userId, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$group2 = (object) array( 'id' => 2, 'teacher_id' => $userId, 'name' => 'G2', 'subject_key' => 'python', 'deleted_at' => null );

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

		$group = (object) array( 'id' => 1, 'teacher_id' => $userId, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$this->groups->method( 'findByTeacherId' )->with( $userId )->willReturn( array( $group ) );

		$this->assertFalse( $this->guard->canManageSubject( $userId, 'inf_ege' ) );
	}

	private function teacherOf( int $userId, string ...$subjects ): void {
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::Admin->value, false );
		$this->grantCap( $userId, Capability::ManageSubjects->value, false );

		$groups = array();
		foreach ( $subjects as $i => $subject ) {
			$groups[] = (object) array( 'id' => $i + 1, 'teacher_id' => $userId, 'name' => 'G' . $i, 'subject_key' => $subject, 'deleted_at' => null );
		}
		$this->groups->method( 'findByTeacherId' )->willReturn( $groups );
	}

	public function test_teacher_manages_own_event_of_own_subject(): void {
		$this->teacherOf( 10, 'inf_ege' );

		$this->assertTrue( $this->guard->canManageEvent( 10, $this->examEvent( array( 'owner_user_id' => '10' ) ) ) );
	}

	public function test_teacher_cannot_manage_foreign_event(): void {
		$this->teacherOf( 11, 'inf_ege' );

		$this->assertFalse( $this->guard->canManageEvent( 11, $this->examEvent( array( 'owner_user_id' => '10' ) ) ), 'Тот же предмет, но проведение чужое.' );
	}

	public function test_teacher_loses_own_event_when_subject_is_no_longer_his(): void {
		$this->teacherOf( 10, 'python' );

		$this->assertFalse( $this->guard->canManageEvent( 10, $this->examEvent( array( 'owner_user_id' => '10', 'subject_key' => 'inf_ege' ) ) ) );
	}

	public function test_admin_manages_any_event(): void {
		$this->grantCap( 3, Capability::ManageExams->value, true );
		$this->grantCap( 3, Capability::Admin->value, true );

		$this->assertTrue( $this->guard->canManageEvent( 3, $this->examEvent( array( 'owner_user_id' => '10' ) ) ) );
	}

	public function test_user_without_manage_exams_cannot_manage_own_event(): void {
		$this->grantCap( 10, Capability::ManageExams->value, false );

		$this->assertFalse( $this->guard->canManageEvent( 10, $this->examEvent( array( 'owner_user_id' => '10' ) ) ) );
	}

	public function test_manageable_subject_keys_intersects_for_teacher(): void {
		$userId = 1;
		$this->grantCap( $userId, Capability::ManageExams->value, true );
		$this->grantCap( $userId, Capability::Admin->value, false );
		$this->grantCap( $userId, Capability::ManageSubjects->value, false );

		$group = (object) array( 'id' => 1, 'teacher_id' => $userId, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$this->groups->method( 'findByTeacherId' )->with( $userId )->willReturn( array( $group ) );

		$allSubjects = array( 'inf_ege', 'python', 'math' );
		$result = $this->guard->manageableSubjectKeys( $userId, $allSubjects );

		$this->assertCount( 1, $result );
		$this->assertContains( 'inf_ege', $result );
	}
}
