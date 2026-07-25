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

	/* ── canWriteJournal (T5.7: read-only оригинала в период замены) ─────── */

	public function test_can_write_journal_admin_bypasses_group_check(): void {
		$GLOBALS['_test_user_can'][1]['manage_options'] = true;
		$this->groups->expects( self::never() )->method( 'findById' );

		self::assertTrue( $this->guard->canWriteJournal( 5, 1 ) );
	}

	public function test_can_write_journal_substitute_during_grant(): void {
		$this->substitutions->method( 'hasActiveGrant' )->with( 99, 7 )->willReturn( true );
		$this->groups->expects( self::never() )->method( 'findById' );

		self::assertTrue( $this->guard->canWriteJournal( 7, 99 ) );
	}

	public function test_can_write_journal_permanent_teacher_blocked_during_substitution(): void {
		$group             = new \stdClass();
		$group->teacher_id = 42;
		$this->groups->method( 'findById' )->with( 7 )->willReturn( $group );
		$this->substitutions->method( 'hasActiveGrant' )->willReturn( false );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( $this->makeSubstitution() );

		// В период активной замены постоянный препод не может писать в журнал.
		self::assertFalse( $this->guard->canWriteJournal( 7, 42 ) );
	}

	public function test_can_write_journal_permanent_teacher_allowed_without_substitution(): void {
		$group             = new \stdClass();
		$group->teacher_id = 42;
		$this->groups->method( 'findById' )->with( 7 )->willReturn( $group );
		$this->substitutions->method( 'hasActiveGrant' )->willReturn( false );
		$this->substitutions->method( 'findActiveForGroup' )->willReturn( null );

		self::assertTrue( $this->guard->canWriteJournal( 7, 42 ) );
	}

	public function test_can_write_journal_returns_false_for_stranger(): void {
		$group             = new \stdClass();
		$group->teacher_id = 42;
		$this->groups->method( 'findById' )->willReturn( $group );
		$this->substitutions->method( 'hasActiveGrant' )->willReturn( false );

		self::assertFalse( $this->guard->canWriteJournal( 7, 99 ) );
	}

	private function makeSubstitution(): \Inc\DTO\Course\SubstitutionDTO {
		return new \Inc\DTO\Course\SubstitutionDTO(
			id                  : 1,
			groupId             : 7,
			originalTeacherId   : 42,
			substituteTeacherId : 99,
			validFrom           : '2026-01-01',
			validTo             : '2030-12-31',
			reason              : null,
			approvedBy          : null,
			createdAt           : '2026-01-01 00:00:00',
		);
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
