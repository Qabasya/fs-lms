<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Exam\ExamParticipantProfileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Школа и группа участника: ученик — из записи, гость — из ссылки преподавателя, самозапись — пусто.
 */
class ExamParticipantProfileServiceTest extends TestCase {

	private StudentRecordRepository&MockObject $records;
	private ExamSourceRepository&MockObject $sources;
	private GroupsRepository&MockObject $groups;
	private ExamParticipantProfileService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->records = $this->createMock( StudentRecordRepository::class );
		$this->sources = $this->createMock( ExamSourceRepository::class );
		$this->groups  = $this->createMock( GroupsRepository::class );
		$this->service = new ExamParticipantProfileService( $this->records, $this->sources, $this->groups );
	}

	private function participant( ?int $personId ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => 1, 'person_id' => $personId, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
	}

	private function participation( ?int $sourceId ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => 1, 'event_id' => 3, 'participant_id' => 1, 'audience' => 'guest', 'source_id' => $sourceId,
			'transfer_allowed' => 0, 'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function source( string $schoolKey, string $schoolName ): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array(
			'id' => 5, 'event_id' => 3, 'school_key' => $schoolKey, 'school_name' => $schoolName, 'school_name_normalized' => $schoolName,
			'grade' => 9, 'teacher_name' => 'Иванова', 'label' => '', 'is_active' => 1, 'key_generation' => 1, 'key_revoked_at' => null,
			'created_by_user_id' => 1, 'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function record( ?string $school, int $groupId = 12 ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 40, 'group_id' => $groupId, 'snapshot_last_name' => 'Иванов', 'snapshot_first_name' => 'Пётр',
			'snapshot_school' => $school, 'status' => 'active', 'enrolled_at' => '2026-01-01', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	public function test_student_school_comes_from_record(): void {
		$this->records->method( 'findActiveByStudentFirst' )->willReturn( $this->record( 'Школа № 5' ) );

		self::assertSame( 'Школа № 5', $this->service->school( $this->participation( null ), $this->participant( 40 ) ) );
	}

	public function test_guest_school_comes_from_teacher_link(): void {
		$this->sources->method( 'find' )->willReturn( $this->source( 'sh5', 'Лицей № 49' ) );

		self::assertSame( 'Лицей № 49', $this->service->school( $this->participation( 5 ), $this->participant( null ) ) );
	}

	public function test_open_registration_guest_school_is_the_one_they_typed(): void {
		$this->sources->method( 'find' )->willReturn( $this->source( ExamSourceDTO::PUBLIC_KEY, 'Открытая запись' ) );
		$participant = ExamParticipantDTO::fromArray( array(
			'id' => 1, 'person_id' => null, 'school_name' => 'Лицей № 49', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );

		self::assertSame( 'Лицей № 49', $this->service->school( $this->participation( 5 ), $participant ) );
	}

	public function test_service_name_of_open_registration_is_not_a_school(): void {
		$this->sources->method( 'find' )->willReturn( $this->source( ExamSourceDTO::PUBLIC_KEY, 'Открытая запись' ) );
		$participant = ExamParticipantDTO::fromArray( array(
			'id' => 1, 'person_id' => null, 'school_name' => 'Открытая запись', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );

		self::assertSame( '', $this->service->school( $this->participation( 5 ), $participant ) );
	}

	public function test_self_registered_guest_has_no_school(): void {
		$this->sources->method( 'find' )->willReturn( $this->source( ExamSourceDTO::PUBLIC_KEY, 'Открытая запись' ) );

		self::assertSame( '', $this->service->school( $this->participation( 5 ), $this->participant( null ) ) );
		self::assertSame( '', $this->service->school( $this->participation( null ), $this->participant( null ) ) );
	}

	public function test_group_name_only_for_our_student(): void {
		$this->records->method( 'findActiveByStudentFirst' )->willReturn( $this->record( null, 12 ) );
		$this->groups->method( 'findById' )->with( 12 )->willReturn( (object) array( 'name' => 'КЕГЭ-1' ) );

		self::assertSame( 'КЕГЭ-1', $this->service->groupName( $this->participant( 40 ) ) );
		self::assertSame( '', $this->service->groupName( $this->participant( null ) ) );
	}
}
