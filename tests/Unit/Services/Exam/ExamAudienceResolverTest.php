<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Enums\Enrollment\EnrollmentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Группы приходят из `$wpdb` как `stdClass` со строковыми колонками — фикстуры такие же (на реальной базе `findActiveByGroupId( '2' )` падал TypeError).
 */
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
		$group1 = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$group2 = (object) array( 'id' => '2', 'teacher_id' => 1, 'name' => 'G2', 'subject_key' => 'inf_ege', 'deleted_at' => null );

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
		$group = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $group ) );

		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array() );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertEmpty( $result );
	}

	public function test_group_of_other_subject_is_ignored(): void {
		$egeGroup = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$ogeGroup = (object) array( 'id' => '2', 'teacher_id' => 1, 'name' => 'G2', 'subject_key' => 'inf_oge', 'deleted_at' => null );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $egeGroup, $ogeGroup ) );

		$egeRecord = $this->createStudentRecord( 1, 10, 20, 1 );

		$this->records->method( 'findActiveByGroupId' )
			->willReturnCallback( fn( $gid ) => $gid === 1 ? array( $egeRecord ) : array() );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 10, $result );
	}

	public function test_ege_and_oge_subjects_do_not_mix(): void {
		$egeGroup = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'КЕГЭ-1', 'subject_key' => 'inf_ege', 'deleted_at' => null );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $egeGroup ) );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array() );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertEmpty( $result );
	}

	public function test_trial_record_is_not_eligible(): void {
		$record = $this->createStudentRecord( 1, 10, 20, 1, true );

		$group = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $group ) );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array( $record ) );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertEmpty( $result );
	}

	public function test_deleted_group_is_ignored(): void {
		$activeGroup = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$deletedGroup = (object) array( 'id' => '2', 'teacher_id' => 1, 'name' => 'G2', 'subject_key' => 'inf_ege', 'deleted_at' => '2026-01-01 00:00:00' );

		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $activeGroup, $deletedGroup ) );

		$record = $this->createStudentRecord( 1, 10, 20, 1 );
		$this->records->method( 'findActiveByGroupId' )->with( 1 )->willReturn( array( $record ) );

		$result = $this->resolver->studentPersonIds( 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 10, $result );
	}

	// ── Общая (дополнительная) группа: допуск к предмету не определяет, занятия учитываются ─────────

	public function test_shared_group_does_not_define_subject_audience(): void {
		$main   = (object) array( 'id' => '1', 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null, 'is_shared' => '0' );
		$shared = (object) array( 'id' => '2', 'name' => 'Общая', 'subject_key' => 'inf_ege', 'deleted_at' => null, 'is_shared' => '1' );
		$this->groups->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array( $main, $shared ) );

		// В общей группе есть ученик, у которого основной группы этого предмета нет: допущенным он не становится.
		$this->records->method( 'findActiveByGroupId' )->willReturnCallback(
			fn( $gid ) => 1 === $gid ? array( $this->createStudentRecord( 1, 10, 20, 1 ) ) : array( $this->createStudentRecord( 2, 11, 21, 2 ) )
		);

		$this->assertSame( array( 10 ), $this->resolver->studentPersonIds( 'inf_ege' ) );
	}

	public function test_shared_group_does_not_make_student_eligible_for_its_subject(): void {
		$this->records->method( 'findActiveByStudent' )->with( 11 )->willReturn( array( $this->createStudentRecord( 2, 11, 21, 2 ) ) );
		$this->groups->method( 'findById' )->willReturn(
			(object) array( 'id' => '2', 'name' => 'Общая', 'subject_key' => 'inf_ege', 'deleted_at' => null, 'is_shared' => '1' )
		);

		$this->assertFalse( $this->resolver->isEligible( 11, 'inf_ege' ) );
		$this->assertSame( array(), $this->resolver->subjectKeysForStudent( 11 ) );
	}

	public function test_shared_group_lessons_still_count_for_exam_overlap(): void {
		$this->records->method( 'findActiveByStudent' )->with( 11 )->willReturn( array( $this->createStudentRecord( 2, 11, 21, 2 ) ) );
		$this->groups->method( 'findById' )->willReturn(
			(object) array( 'id' => '2', 'name' => 'Общая', 'subject_key' => 'inf_ege', 'deleted_at' => null, 'is_shared' => '1' )
		);

		// Сеанс экзамена может прийтись на занятие общей группы — предупреждение строится по этим группам.
		$this->assertSame( array( 2 ), $this->resolver->groupIdsForStudent( 11 ) );
	}

	public function test_shared_group_does_not_add_guardian_of_subject(): void {
		$this->records->method( 'findActiveByStudent' )->with( 11 )->willReturn( array( $this->createStudentRecord( 2, 11, 21, 2 ) ) );
		$this->groups->method( 'findById' )->willReturn(
			(object) array( 'id' => '2', 'name' => 'Общая', 'subject_key' => 'inf_ege', 'deleted_at' => null, 'is_shared' => '1' )
		);

		$this->assertSame( array(), $this->resolver->guardianPersonIds( 11, 'inf_ege' ) );
	}

	public function test_subject_keys_for_student_are_unique(): void {
		$record1 = $this->createStudentRecord( 1, 10, 20, 1 );
		$record2 = $this->createStudentRecord( 2, 10, 20, 2 );

		$this->records->method( 'findActiveByStudent' )->with( 10 )->willReturn( array( $record1, $record2 ) );

		$group1 = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$group2 = (object) array( 'id' => '2', 'teacher_id' => 1, 'name' => 'G2', 'subject_key' => 'inf_ege', 'deleted_at' => null );

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

		$group = (object) array( 'id' => '1', 'teacher_id' => 1, 'name' => 'G1', 'subject_key' => 'inf_ege', 'deleted_at' => null );
		$this->groups->method( 'findById' )->willReturn( $group );

		$result = $this->resolver->guardianPersonIds( 10, 'inf_ege' );

		$this->assertCount( 1, $result );
		$this->assertContains( 20, $result );
		$this->assertNotContains( 0, $result );
	}
}
