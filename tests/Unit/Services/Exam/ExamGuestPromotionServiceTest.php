<?php

declare( strict_types=1 );

namespace Unit\Services\Exam;

use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Exam\ExamGuestPromotionService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Гость экзамена зачислен: его участник привязывается к ученику, участия становятся ученическими, оценённые попытки утверждаются.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamGuestPromotionServiceTest extends TestCase {

	private ExamParticipantRepository&MockObject $participants;
	private ExamParticipationRepository&MockObject $participations;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ExamGuestPromotionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$time                 = $this->createMock( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-10-26 10:00:00' );
		$time->method( 'nowLocal' )->willReturn( '2026-10-26 13:00:00' );

		$this->service = new ExamGuestPromotionService( $this->participants, $this->participations, $this->attempts, $time );
	}

	private function participant( int $id, ?int $personId = null, ?string $anonymizedAt = null ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array(
			'id' => $id, 'person_id' => $personId, 'anonymized_at' => $anonymizedAt,
			'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
		) );
	}

	public function test_guest_is_linked_and_results_become_students(): void {
		$this->participants->method( 'find' )->willReturn( $this->participant( 5 ) );
		$this->participants->method( 'findByPersonId' )->willReturn( null );
		$this->participants->expects( self::once() )->method( 'linkToPerson' )->with( 5, 77, '2026-10-26 10:00:00' )->willReturn( true );
		$this->participations->expects( self::once() )->method( 'makeStudentAudience' )->with( 5, '2026-10-26 10:00:00' );
		$this->attempts->expects( self::once() )->method( 'assignStudentToParticipantAttempts' )->with( 5, 77 );
		$this->attempts->expects( self::once() )->method( 'approveRevealedOfParticipant' )->with( 5, 3, '2026-10-26 13:00:00' );

		self::assertTrue( $this->service->promote( 5, 77, 3 ) );
	}

	public function test_existing_student_participant_takes_the_participations(): void {
		$this->participants->method( 'find' )->willReturn( $this->participant( 5 ) );
		$this->participants->method( 'findByPersonId' )->willReturn( $this->participant( 8, 77 ) );
		$this->participants->expects( self::never() )->method( 'linkToPerson' );
		$this->participations->expects( self::once() )->method( 'moveToParticipant' )->with( 5, 8 );
		$this->participants->expects( self::once() )->method( 'deleteWithoutParticipations' )->with( 5 );
		$this->participations->expects( self::once() )->method( 'makeStudentAudience' )->with( 8, self::anything() );
		$this->attempts->expects( self::once() )->method( 'assignStudentToParticipantAttempts' )->with( 8, 77 );

		self::assertTrue( $this->service->promote( 5, 77, 3 ) );
	}

	public function test_nothing_to_link_for_student_anonymized_or_unknown_participant(): void {
		$this->participants->method( 'find' )->willReturnOnConsecutiveCalls(
			$this->participant( 5, 70 ),
			$this->participant( 6, null, '2026-10-02 00:00:00' ),
			null
		);
		$this->participants->expects( self::never() )->method( 'linkToPerson' );

		self::assertFalse( $this->service->promote( 5, 77, 3 ) );
		self::assertFalse( $this->service->promote( 6, 77, 3 ) );
		self::assertFalse( $this->service->promote( 7, 77, 3 ) );
	}
}
