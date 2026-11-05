<?php
declare( strict_types=1 );
namespace Unit\Services\Enrollment;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Course\CourseDTO;
use Inc\Managers\Course\CourseManager;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Course\SelfPacedContentGuard;
use Inc\Services\Enrollment\SelfPacedAccessPolicy;
use PHPUnit\Framework\TestCase;
class SelfPacedAccessPolicyTest extends TestCase {
	private GroupsRepository $groups;
	private StudentRecordRepository $records;
	private CourseManager $courses;
	private SelfPacedAccessPolicy $policy;
	private GroupLessonRepository $lessons;
	private \Inc\Managers\Course\LessonManager $lessonBank;
	protected function setUp(): void {
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->courses = $this->createMock( CourseManager::class );
		$this->lessons = $this->createMock( GroupLessonRepository::class );
		$this->lessonBank = $this->createMock( \Inc\Managers\Course\LessonManager::class );
		$this->policy = new SelfPacedAccessPolicy( $this->groups, $this->records, $this->courses, $this->createMock( SelfPacedContentGuard::class ), $this->lessons, $this->lessonBank );
	}
	public function test_additional_trial_and_service_records_never_grant_audience(): void {
		$this->records->method( 'findActiveByStudent' )->willReturn( array(
			StudentRecordDTO::fromArray( array( 'group_id' => 1, 'is_trial' => 1 ) ),
			StudentRecordDTO::fromArray( array( 'group_id' => 2 ) ),
			StudentRecordDTO::fromArray( array( 'group_id' => 3 ) ),
			StudentRecordDTO::fromArray( array( 'group_id' => 4 ) ),
		) );
		$this->groups->method( 'findById' )->willReturnCallback( static fn( int $id ): object => (object) array( 'id' => $id, 'deleted_at' => null, 'is_shared' => 2 === $id ? 1 : 0, 'self_paced_course_id' => 3 === $id ? 77 : null, 'access_mode' => 4 === $id ? 'open' : 'scheduled', 'subject_key' => 'inf' ) );
		self::assertNull( $this->policy->eligibleBasis( 8, array( 'inf' ) ) );
	}
	public function test_basis_requires_matching_subject_and_live_primary_record(): void {
		$record = StudentRecordDTO::fromArray( array( 'group_id' => 1 ) );
		$this->records->method( 'findActiveByStudent' )->willReturn( array( $record ) );
		$this->groups->method( 'findById' )->willReturn( (object) array( 'deleted_at' => null, 'subject_key' => 'inf', 'access_mode' => 'scheduled' ) );
		self::assertSame( $record, $this->policy->eligibleBasis( 8, array( 'inf' ) ) );
		self::assertNull( $this->policy->eligibleBasis( 8, array( 'math' ) ) );
	}
	public function test_service_record_without_frozen_basis_is_denied(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'self_paced_course_id' => 77 ) );
		self::assertFalse( $this->policy->canContinue( StudentRecordDTO::fromArray( array( 'group_id' => 9 ) ) ) );
	}
	public function test_legacy_group_uses_existing_policy_unchanged(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'self_paced_course_id' => null ) );
		self::assertTrue( $this->policy->canContinue( StudentRecordDTO::fromArray( array( 'group_id' => 9 ) ) ) );
	}
	public function test_paused_record_resumes_on_original_direction_even_after_offer_and_audience_change(): void {
		$this->lessonBank->method( 'get' )->willReturn( \Inc\DTO\Course\LessonDTO::fromArray( array( 'id' => 3, 'status' => 'publish' ) ) );
		$active = false;
		$this->records->method( 'findActiveByStudent' )->willReturnCallback( static function() use ( &$active ): array {
			return $active ? array( StudentRecordDTO::fromArray( array( 'group_id' => 2 ) ) ) : array();
		} );
		$this->groups->method( 'findById' )->willReturnCallback( static fn( int $id ): object => 9 === $id
			? (object) array( 'self_paced_course_id' => 77, 'course_id' => 77, 'deleted_at' => null, 'access_mode' => 'open' )
			: (object) array( 'subject_key' => 'inf', 'deleted_at' => null, 'access_mode' => 'scheduled' ) );
		$this->courses->method( 'get' )->willReturn( CourseDTO::fromArray( array( 'id' => 77, 'service_group_id' => 9, 'status' => 'publish', 'self_paced_enabled' => false, 'audience_subject_keys' => array( 'other' ), 'modules' => array( array( 'lesson_ids' => array( 3 ) ) ) ) ) );
		$this->lessons->method( 'listByGroup' )->willReturn( array( \Inc\DTO\Course\GroupLessonDTO::fromArray( array( 'id' => 1, 'group_id' => 9, 'position' => 1, 'lesson_id' => 3, 'visibility' => 'open' ) ) ) );
		$record = StudentRecordDTO::fromArray( array( 'group_id' => 9, 'student_person_id' => 8, 'self_paced_basis_subject' => 'inf' ) );
		self::assertFalse( $this->policy->canContinue( $record ) );
		$active = true;
		self::assertTrue( $this->policy->canContinue( $record ) );
	}

	public function test_basis_is_nullable_for_old_rows_and_input_round_trips(): void {
		self::assertNull( StudentRecordDTO::fromArray( array() )->selfPacedBasisSubject );
		$input = new \Inc\DTO\Enrollment\StudentRecordInputDTO( studentPersonId: 1, parentPersonId: 2, status: 'active', enrolledAt: '', createdAt: '', updatedAt: '', selfPacedBasisSubject: 'inf' );
		self::assertSame( 'inf', StudentRecordDTO::fromArray( $input->toArray() )->selfPacedBasisSubject );
	}


	public function test_ready_rejects_unpublished_bank_lesson_even_with_open_program_row(): void {
		$this->groups->method( 'findById' )->willReturn( (object) array( 'self_paced_course_id' => 77, 'course_id' => 77, 'deleted_at' => null, 'access_mode' => 'open' ) );
		$this->lessonBank->method( 'get' )->willReturn( \Inc\DTO\Course\LessonDTO::fromArray( array( 'id' => 3, 'status' => 'draft' ) ) );
		$course = CourseDTO::fromArray( array( 'id' => 77, 'service_group_id' => 9, 'status' => 'publish', 'modules' => array( array( 'lesson_ids' => array( 3 ) ) ) ) );
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'не опубликован' );
		$this->policy->assertReady( $course );
	}
}
