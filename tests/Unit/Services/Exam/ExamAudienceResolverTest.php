<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Course\GroupDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use PHPUnit\Framework\TestCase;

class ExamAudienceResolverTest extends TestCase {

	private GroupsRepository $groups;
	private StudentRecordRepository $records;
	private ExamAudienceResolver $resolver;

	protected function setUp(): void {
		$this->groups = $this->createMock( GroupsRepository::class );
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->resolver = new ExamAudienceResolver( $this->groups, $this->records );
	}

	private function createStudentRecord(
		int $id,
		int $studentId,
		int $parentId,
		int $groupId,
		bool $isTrial = false
	): StudentRecordDTO {
		return new StudentRecordDTO(
			$id, $studentId, $parentId, $groupId, 'Фамилия', 'Имя', null, null, null,
			null, null, null, null, EnrollmentStatus::Active, '2026-01-01',
			null, null, null, null, '', '', $isTrial
		);
	}

	public function test_student_in_two_groups_of_subject_listed_once(): void {
		$group1 = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$group2 = new GroupDTO( 2, 1, 'G2', 'inf_ege', 1, null, false, '' );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $group1, $group2 ) );

		$record1 = $this->createStudentRecord( 1, 10, 20, 1 );
		$record2 = $this->createStudentRecord( 2, 10, 20, 2 );

		$this->records->method( 'findActiveByGroupId' )
			->willReturnCallback( fn( $gid ) => $gid === 1 ? array( $record1 ) : array( $record2 ) );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 10, $result );
	}

	public function test_expelled_student_is_not_in_audience(): void {
		$group = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $group ) );

		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array() );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertEmpty( $result );
	}

	public function test_group_of_other_subject_is_ignored(): void {
		$egeGroup = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$ogeGroup = new GroupDTO( 2, 1, 'G2', 'inf_oge', 1, null, false, '' );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $egeGroup, $ogeGroup ) );

		$egeRecord = $this->createStudentRecord( 1, 10, 20, 1 );

		$this->records->method( 'findActiveByGroupId' )
			->willReturnCallback( fn( $gid ) => $gid === 1 ? array( $egeRecord ) : array() );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 10, $result );
	}

	public function test_ege_and_oge_subjects_do_not_mix(): void {
		$egeGroup = new GroupDTO( 1, 1, 'КЕГЭ-1', 'inf_ege', 1, null, false, '' );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $egeGroup ) );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array() );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertEmpty( $result );
	}

	public function test_trial_record_is_not_eligible(): void {
		$record = $this->createStudentRecord( 1, 10, 20, 1, true );

		$group = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $group ) );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array( $record ) );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertEmpty( $result );
	}

	public function test_deleted_group_is_ignored(): void {
		$activeGroup = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$deletedGroup = new GroupDTO( 2, 1, 'G2', 'inf_ege', 1, '2026-01-01 00:00:00', false, '' );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $activeGroup, $deletedGroup ) );

		$record = $this->createStudentRecord( 1, 10, 20, 1 );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array( $record ) );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 10, $result );
	}

	public function test_subject_keys_for_student_are_unique(): void {
		$record1 = $this->createStudentRecord( 1, 10, 20, 1 );
		$record2 = $this->createStudentRecord( 2, 10, 20, 2 );

		$this->records->method( 'findActiveByStudent' )->with( 10 )->willReturn( array( $record1, $record2 ) );

		$group1 = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$group2 = new GroupDTO( 2, 1, 'G2', 'inf_ege', 1, null, false, '' );

		$this->groups->method( 'findById' )
			->willReturnCallback( fn( $id ) => $id === 1 ? $group1 : $group2 );

		$result = $this->resolver->subjectKeysForStudent( 10 );

		$this->assertCount( 1, $result );
		$this->assertContains( 'inf_ege', $result );
	}

	public function test_guardians_are_unique_and_non_zero(): void {
		$record1 = $this->createStudentRecord( 1, 10, 20, 1 );
		$record2 = $this->createStudentRecord( 2, 10, 20, 2 );
		$record3 = $this->createStudentRecord( 3, 10, 0, 3 );

		$this->records->method( 'findActiveByStudent' )->with( 10 )->willReturn( array( $record1, $record2, $record3 ) );

		$group = new GroupDTO( 1, 1, 'G1', 'inf_ege', 1, null, false, '' );
		$this->groups->method( 'findById' )->willReturn( $group );

		$result = $this->resolver->guardianPersonIds( 10, 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 20, $result );
		$this->assertNotContains( 0, $result );
	}
}
