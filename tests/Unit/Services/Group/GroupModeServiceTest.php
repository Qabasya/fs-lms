<?php

declare( strict_types=1 );

namespace Unit\Services\Group;

use Inc\Enums\Course\AccessMode;
use Inc\Enums\Course\GroupType;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Group\GroupModeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Смена режима группы «по расписанию» ↔ «индивидуальная».
 */
class GroupModeServiceTest extends TestCase {

	private GroupsRepository&MockObject $groups;
	private StudentRecordRepository&MockObject $records;
	private GroupModeService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->groups  = $this->createMock( GroupsRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->service = new GroupModeService( $this->groups, $this->records );
	}

	public function test_service_group_cannot_be_edited_through_ordinary_group_type_command(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'open', 'self_paced_course_id' => 100 ) );
		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->service->change( 5, AccessMode::Open, GroupType::Open );
	}

	private function group( string $mode ): object {
		return (object) array( 'id' => 5, 'access_mode' => $mode );
	}

	public function test_scheduled_to_individual_clears_schedule_room_and_lock(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'scheduled' ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 1 );

		$this->groups->expects( self::once() )->method( 'lockForUpdate' )->with( 5 );
		$this->groups->expects( self::once() )->method( 'update' )
			->with( 5, array( 'access_mode' => 'individual', 'meetings' => '[]', 'room_id' => null, 'program_locked_at' => null ) )
			->willReturn( true );

		self::assertTrue( $this->service->change( 5, AccessMode::Individual ) );
	}

	public function test_empty_group_can_become_individual(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'scheduled' ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 0 );
		$this->groups->method( 'update' )->willReturn( true );

		self::assertTrue( $this->service->change( 5, AccessMode::Individual ) );
	}

	public function test_group_with_several_students_cannot_become_individual(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'scheduled' ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 3 );

		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( '3 учеников' );

		$this->service->change( 5, AccessMode::Individual );
	}

	/** Обратно в «по расписанию» меняется только режим: расписание преподаватель задаёт в карточке группы. */
	public function test_individual_to_scheduled_changes_only_mode(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'individual' ) );

		$this->groups->expects( self::once() )->method( 'update' )
			->with( 5, array( 'access_mode' => 'scheduled' ) )
			->willReturn( true );

		self::assertTrue( $this->service->change( 5, AccessMode::Scheduled ) );
	}

	public function test_same_mode_is_noop(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'individual' ) );

		$this->groups->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->change( 5, AccessMode::Individual ) );
	}

	public function test_open_group_mode_is_fixed(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'open' ) );

		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );

		$this->service->change( 5, AccessMode::Individual );
	}

	public function test_group_cannot_become_open(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'scheduled' ) );

		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );

		$this->service->change( 5, AccessMode::Open );
	}

	public function test_missing_group_is_reported(): void {
		$this->groups->method( 'findById' )->willReturn( null );

		$this->expectException( \InvalidArgumentException::class );

		$this->service->change( 5, AccessMode::Individual );
	}
	public function test_shared_to_individual_updates_both_flags_in_one_write(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'scheduled', 'is_shared' => 1 ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 1 );
		$this->groups->expects( self::once() )->method( 'update' )
			->with( 5, array( 'is_shared' => 0, 'access_mode' => 'individual', 'meetings' => '[]', 'room_id' => null, 'program_locked_at' => null ) )
			->willReturn( true );
		self::assertTrue( $this->service->change( 5, AccessMode::Individual, GroupType::Individual ) );
	}

	public function test_scheduled_to_shared_keeps_schedule_and_only_changes_flag(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'scheduled' ) );
		$this->groups->expects( self::once() )->method( 'update' )->with( 5, array( 'is_shared' => 1 ) )->willReturn( true );
		self::assertTrue( $this->service->change( 5, AccessMode::Scheduled, GroupType::Shared ) );
	}

	public function test_legacy_open_shared_can_be_saved_without_conversion(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'open', 'is_shared' => 1 ) );
		$this->groups->expects( self::never() )->method( 'update' );
		self::assertFalse( $this->service->change( 5, AccessMode::Open, GroupType::LegacyOpenShared ) );
	}

	public function test_legacy_shared_flag_cannot_be_silently_removed(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'open', 'is_shared' => 1 ) );
		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->service->change( 5, AccessMode::Open, GroupType::Open );
	}

	public function test_legacy_combination_cannot_be_created_by_editing_normal_group(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'individual' ) );
		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->service->change( 5, AccessMode::Individual, GroupType::LegacyIndividualShared );
	}

	public function test_shared_to_individual_still_checks_student_count(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'scheduled', 'is_shared' => 1 ) );
		$this->records->method( 'countActiveByGroup' )->willReturn( 2 );
		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->service->change( 5, AccessMode::Individual, GroupType::Individual );
	}

	public function test_inconsistent_type_and_access_mode_are_rejected(): void {
		$this->groups->method( 'findById' )->willReturn( $this->group( 'scheduled' ) );
		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \InvalidArgumentException::class );
		$this->service->change( 5, AccessMode::Individual, GroupType::Shared );
	}

	public function test_old_caller_cannot_convert_legacy_group(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'individual', 'is_shared' => 1 ) );
		$this->groups->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->service->change( 5, AccessMode::Scheduled );
	}

	public function test_old_caller_saving_same_legacy_individual_keeps_shared_flag(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'access_mode' => 'individual', 'is_shared' => 1 ) );
		$this->groups->expects( self::never() )->method( 'update' );
		self::assertFalse( $this->service->change( 5, AccessMode::Individual ) );
	}

}
