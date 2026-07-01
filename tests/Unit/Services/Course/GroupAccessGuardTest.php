<?php

declare( strict_types=1 );

namespace Unit\Services\Course;

use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\SubstitutionRepository;
use Inc\Services\Course\GroupAccessGuard;
use PHPUnit\Framework\TestCase;

class GroupAccessGuardTest extends TestCase {

	private GroupsRepository&\PHPUnit\Framework\MockObject\MockObject $groups;
	private StudentRecordRepository&\PHPUnit\Framework\MockObject\MockObject $studentRecords;
	private SubstitutionRepository&\PHPUnit\Framework\MockObject\MockObject $substitutions;
	private GroupAccessGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->groups         = $this->createMock( GroupsRepository::class );
		$this->studentRecords = $this->createMock( StudentRecordRepository::class );
		$this->substitutions  = $this->createMock( SubstitutionRepository::class );
		$this->guard          = new GroupAccessGuard( $this->groups, $this->studentRecords, $this->substitutions );
		$GLOBALS['_test_user_can'] = [];
	}

	public function test_can_manage_active_substitute_grant(): void {
		$group             = new \stdClass();
		$group->teacher_id = 42;
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->substitutions->method( 'hasActiveGrant' )->with( 99, 7 )->willReturn( true );

		self::assertTrue( $this->guard->canManage( 7, 99 ) );
	}

	public function test_can_manage_admin_bypasses_group_check(): void {
		$GLOBALS['_test_user_can'][1]['manage_options'] = true;
		$this->groups->expects( self::never() )->method( 'findById' );

		self::assertTrue( $this->guard->canManage( 5, 1 ) );
	}

	public function test_can_manage_teacher_of_group(): void {
		$group             = new \stdClass();
		$group->teacher_id = 42;
		$this->groups->method( 'findById' )->with( 7 )->willReturn( $group );

		self::assertTrue( $this->guard->canManage( 7, 42 ) );
	}

	public function test_can_manage_returns_false_for_other_user(): void {
		$group             = new \stdClass();
		$group->teacher_id = 42;
		$this->groups->method( 'findById' )->willReturn( $group );

		self::assertFalse( $this->guard->canManage( 7, 99 ) );
	}

	public function test_can_manage_returns_false_when_group_not_found(): void {
		$this->groups->method( 'findById' )->willReturn( null );

		self::assertFalse( $this->guard->canManage( 99, 1 ) );
	}

	public function test_is_member_ever_returns_true_when_record_exists(): void {
		$this->studentRecords->method( 'countByGroupAndPerson' )->with( 5, 3 )->willReturn( 1 );

		self::assertTrue( $this->guard->isMemberEver( 5, 3 ) );
	}

	public function test_is_member_ever_returns_false_when_no_record(): void {
		$this->studentRecords->method( 'countByGroupAndPerson' )->willReturn( 0 );

		self::assertFalse( $this->guard->isMemberEver( 5, 3 ) );
	}

	public function test_is_parent_of_returns_true_when_record_exists(): void {
		$this->studentRecords->method( 'countByGroupAndParent' )->with( 5, 7 )->willReturn( 2 );

		self::assertTrue( $this->guard->isParentOf( 5, 7 ) );
	}

	public function test_is_parent_of_returns_false_when_no_record(): void {
		$this->studentRecords->method( 'countByGroupAndParent' )->willReturn( 0 );

		self::assertFalse( $this->guard->isParentOf( 5, 7 ) );
	}
}
