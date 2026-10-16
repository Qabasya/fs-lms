<?php

declare( strict_types=1 );

namespace Unit\Services\Log;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Log\ExamLogInputDTO;
use Inc\Enums\Exam\ExamLogAction;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\Log\ExamLogRepository;
use Inc\Services\Exam\ExamParticipantNameService;
use Inc\Services\Log\ExamLogWriter;
use PHPUnit\Framework\TestCase;

/** Журнал «Экзамены»: писатель достраивает связи и ФИО, определяет актора и не роняет действие. */
class ExamLogWriterTest extends TestCase {

	/** @var ExamLogInputDTO[] */
	private array $written = array();

	private ExamLogRepository&\PHPUnit\Framework\MockObject\Stub $repository;
	private ExamRegistrationRepository&\PHPUnit\Framework\MockObject\Stub $registrations;
	private ExamParticipationRepository&\PHPUnit\Framework\MockObject\Stub $participations;
	private ExamParticipantRepository&\PHPUnit\Framework\MockObject\Stub $participants;
	private AssessmentAttemptRepository&\PHPUnit\Framework\MockObject\Stub $attempts;
	private ExamParticipantNameService&\PHPUnit\Framework\MockObject\Stub $names;
	private ExamLogWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		fs_test_reset_ajax();

		$this->repository = $this->createStub( ExamLogRepository::class );
		$this->repository->method( 'create' )->willReturnCallback(
			function ( ExamLogInputDTO $input ): int {
				$this->written[] = $input;

				return count( $this->written );
			}
		);

		$this->registrations  = $this->createStub( ExamRegistrationRepository::class );
		$this->participations = $this->createStub( ExamParticipationRepository::class );
		$this->participants   = $this->createStub( ExamParticipantRepository::class );
		$this->attempts       = $this->createStub( AssessmentAttemptRepository::class );
		$this->names          = $this->createStub( ExamParticipantNameService::class );

		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-16 10:00:00' );

		$this->writer = new ExamLogWriter( $this->repository, $clock, $this->registrations, $this->participations, $this->participants, $this->attempts, $this->names );
	}

	private function chain(): void {
		$this->registrations->method( 'find' )->willReturn( ExamRegistrationDTO::fromArray( array( 'id' => 5, 'participation_id' => 20, 'session_id' => 7, 'status' => 'confirmed', 'created_at' => '2026-10-01 00:00:00' ) ) );
		$this->participations->method( 'find' )->willReturn(
			ExamParticipationDTO::fromArray( array( 'id' => 20, 'event_id' => 3, 'participant_id' => 30, 'audience' => 'student', 'transfer_allowed' => 0, 'version' => 1, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00' ) )
		);
		$this->participants->method( 'find' )->willReturn( ExamParticipantDTO::fromArray( array( 'id' => 30, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00' ) ) );
		$this->names->method( 'participantName' )->willReturn( 'Иванов Иван' );
	}

	public function test_registration_id_completes_session_event_and_participant_name(): void {
		$this->chain();

		$this->writer->write( ExamLogAction::RegistrationCancelledStaff, array( 'registration_id' => 5, 'actor' => 9, 'context' => array( 'reason' => 'болезнь' ) ) );

		$row = $this->written[0]->toArray();
		self::assertSame( 'registration_cancelled_staff', $row['action'] );
		self::assertSame( 7, $row['session_id'] );
		self::assertSame( 3, $row['event_id'] );
		self::assertSame( 5, $row['registration_id'] );
		self::assertSame( 'Иванов Иван', $row['subject_label'] );
		self::assertSame( 9, $row['actor_user_id'] );
		self::assertSame( 'user', $row['actor_type'] );
		self::assertSame( '2026-10-16 10:00:00', $row['created_at'] );
		self::assertSame( '{"reason":"болезнь"}', $row['context'] );
	}

	public function test_attempt_id_resolves_participant_through_participation(): void {
		$this->chain();
		$this->attempts->method( 'find' )->willReturn( AttemptDTO::fromArray( array( 'id' => 77, 'assessment_id' => 1, 'attempt_number' => 1, 'started_at' => '2026-10-16 09:00:00', 'deadline_at' => '2026-10-16 12:00:00', 'exam_participation_id' => 20 ) ) );

		$this->writer->write( ExamLogAction::AttemptSubmitted, array( 'attempt_id' => 77 ) );

		$row = $this->written[0]->toArray();
		self::assertSame( 77, $row['attempt_id'] );
		self::assertSame( 3, $row['event_id'] );
		self::assertSame( 'Иванов Иван', $row['subject_label'] );
	}

	public function test_without_actor_the_current_request_user_is_the_actor(): void {
		$GLOBALS['_fs_test_user_id'] = 42;

		$this->writer->write( ExamLogAction::Registered, array( 'event_id' => 3 ) );

		self::assertSame( 42, $this->written[0]->actorUserId );
		self::assertSame( 'user', $this->written[0]->actorType );
	}

	public function test_explicit_guest_or_system_type_never_takes_the_request_user(): void {
		$GLOBALS['_fs_test_user_id'] = 42;

		$this->writer->write( ExamLogAction::AttemptStarted, array( 'actor_type' => 'guest', 'actor_label' => 'Гость' ) );
		$this->writer->write( ExamLogAction::NoShow, array( 'actor_type' => 'system', 'actor_label' => 'Система' ) );

		foreach ( $this->written as $input ) {
			self::assertNull( $input->actorUserId );
		}
		self::assertSame( 'guest', $this->written[0]->actorType );
		self::assertSame( 'Система', $this->written[1]->actorLabel );
	}

	public function test_no_actor_and_no_session_is_the_system(): void {
		$this->writer->write( ExamLogAction::AttemptExpired, array( 'event_id' => 3 ) );

		self::assertSame( 'system', $this->written[0]->actorType );
		self::assertNull( $this->written[0]->actorUserId );
	}

	public function test_unknown_participant_falls_back_to_the_number(): void {
		$this->participants->method( 'find' )->willReturn( null );

		$this->writer->write( ExamLogAction::Admitted, array( 'participant_id' => 31 ) );

		self::assertSame( 'Участник #31', $this->written[0]->subjectLabel );
	}

	public function test_failure_result_and_unknown_values_are_normalized(): void {
		$this->writer->write( ExamLogAction::PaymentReceived, array( 'result' => 'failure' ) );
		$this->writer->write( ExamLogAction::PaymentReceived, array( 'result' => 'whatever' ) );

		self::assertSame( 'failure', $this->written[0]->result );
		self::assertSame( 'success', $this->written[1]->result );
	}

	public function test_repository_failure_never_breaks_the_action(): void {
		$repository = $this->createStub( ExamLogRepository::class );
		$repository->method( 'create' )->willThrowException( new \RuntimeException( 'table is gone' ) );
		$clock = $this->createStub( ClockInterface::class );
		$clock->method( 'now' )->willReturn( '2026-10-16 10:00:00' );
		$writer = new ExamLogWriter( $repository, $clock, $this->registrations, $this->participations, $this->participants, $this->attempts, $this->names );

		$writer->write( ExamLogAction::EventCreated, array( 'event_id' => 3 ) );

		self::assertTrue( true );
	}

	public function test_record_publishes_the_action_on_the_shared_hook(): void {
		$GLOBALS['_fs_test_actions'] = array();

		ExamLogAction::SessionDeleted->record( array( 'session_id' => 7 ) );

		$call = $GLOBALS['_fs_test_actions'][0];
		self::assertSame( ExamLogAction::HOOK, $call['hook'] );
		self::assertSame( ExamLogAction::SessionDeleted, $call['args'][0] );
		self::assertSame( array( 'session_id' => 7 ), $call['args'][1] );
	}
}
