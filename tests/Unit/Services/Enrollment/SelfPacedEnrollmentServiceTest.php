<?php
declare( strict_types=1 );
namespace Unit\Services\Enrollment;
use Inc\Contracts\ClockInterface;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Course\CourseDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Enums\Access\UserRole;
use Inc\Managers\Course\CourseManager;
use Inc\Managers\Wp\PostManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Enrollment\SelfPacedEnrollmentService;
use Inc\Services\Enrollment\SelfPacedAccessPolicy;
use PHPUnit\Framework\TestCase;
class SelfPacedEnrollmentServiceTest extends TestCase {
	private GroupsRepository $groups;
	private StudentRecordRepository $records;
	private PersonRepository $persons;
	private CourseManager $courses;
	private SelfPacedAccessPolicy $access;
	private SelfPacedEnrollmentService $service;
	private \Inc\Services\Assessment\ExamLockService $lock;
	private \Inc\Managers\Person\UserManager $users;
	protected function setUp(): void {
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->persons = $this->createMock( PersonRepository::class );
		$this->courses = $this->createMock( CourseManager::class );
		$this->access = $this->createMock( SelfPacedAccessPolicy::class );
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-11-05 12:00:00' );
		$this->users = $this->getMockBuilder( \Inc\Managers\Person\UserManager::class )->onlyMethods( array( 'create' ) )->getMock();
		$this->users->expects( self::never() )->method( 'create' );
		$this->lock = $this->createMock( \Inc\Services\Assessment\ExamLockService::class );
		$this->service = new SelfPacedEnrollmentService( $this->groups, $this->records, $this->persons, $this->courses, $this->createMock( PostManager::class ), $this->access, $clock, $this->users, $this->lock );
		$user = new \WP_User(); $user->roles = array( UserRole::FSStudent->value );
		$GLOBALS['_fs_test_userdata'][7] = $user;
		$this->persons->expects( self::never() )->method( 'create' );
		$this->persons->method( 'findByWpUserId' )->willReturn( PersonDTO::fromArray( array( 'id' => 8, 'is_student' => 1, 'created_at' => '', 'updated_at' => '' ) ) );
		$this->groups->method( 'withCourseTransaction' )->willReturnCallback( static fn( int $id, callable $fn ): mixed => $fn() );
		$this->courses->method( 'get' )->willReturn( CourseDTO::fromArray( array( 'id' => 15, 'status' => 'publish', 'self_paced_enabled' => true, 'service_group_id' => 27, 'audience_subject_keys' => array( 'inf' ) ) ) );
		$this->access->method( 'eligibleBasis' )->willReturn( StudentRecordDTO::fromArray( array( 'id' => 2, 'group_id' => 3, 'parent_person_id' => 9, 'snapshot_last_name' => 'Иванов' ) ) );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'subject_key' => 'inf' ) );
		$GLOBALS['_fs_test_actions'] = array();
	}
	public function test_new_start_is_quiet_and_copies_primary_snapshot_without_documents(): void {
		$this->records->method( 'findAllByStudentAndGroup' )->willReturn( array() );
		$this->records->expects( self::once() )->method( 'create' )->willReturnCallback( static function( $dto ): int {
			self::assertSame( 8, $dto->studentPersonId ); self::assertSame( 'inf', $dto->selfPacedBasisSubject );
			self::assertSame( 'Иванов', $dto->snapshotLastName ); self::assertNull( $dto->contractNo ); self::assertNull( $dto->orderNo ); return 99;
		} );
		self::assertSame( array( 'course_id' => 15, 'group_id' => 27, 'record_id' => 99 ), $this->service->start( 15, 7 ) );
		self::assertSame( array(), $GLOBALS['_fs_test_actions'] );
	}
	public function test_repeat_returns_same_record_without_inserting_or_updating(): void {
		$this->records->method( 'findAllByStudentAndGroup' )->willReturn( array( StudentRecordDTO::fromArray( array( 'id' => 99, 'self_paced_basis_subject' => 'inf' ) ) ) );
		$this->records->expects( self::never() )->method( 'create' ); $this->records->expects( self::never() )->method( 'update' );
		self::assertSame( 99, $this->service->start( 15, 7 )['record_id'] );
	}
	public function test_closed_record_reopens_in_place_using_original_basis(): void {
		$this->records->method( 'findAllByStudentAndGroup' )->willReturn( array( StudentRecordDTO::fromArray( array( 'id' => 99, 'status' => 'expelled', 'self_paced_basis_subject' => 'inf' ) ) ) );
		$this->records->expects( self::never() )->method( 'create' );
		$this->records->expects( self::once() )->method( 'update' )->with( 99, self::callback( static fn( array $data ): bool => 'active' === $data['status'] && ! isset( $data['self_paced_basis_subject'] ) ) )->willReturn( true );
		self::assertSame( 99, $this->service->start( 15, 7 )['record_id'] );
	}
	public function test_parent_cannot_start_a_course(): void {
		$GLOBALS['_fs_test_userdata'][7]->roles = array( UserRole::FSParent->value );
		$this->groups->expects( self::never() )->method( 'withCourseTransaction' );
		$this->expectException( \DomainException::class ); $this->service->start( 15, 7 );
	}
	public function test_exam_lock_denies_start_without_writing(): void {
		$this->lock->method( 'isLocked' )->willReturn( true );
		$this->records->expects( self::never() )->method( 'create' );
		$this->records->expects( self::never() )->method( 'update' );
		$this->expectException( \DomainException::class );
		$this->service->start( 15, 7 );
	}

}
