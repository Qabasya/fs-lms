<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamNoShowService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Неявка: к плановому концу сеанса попытка не начата — запись «пропущено», место свободно, исход ровно один.
 * Сеанс 07:00–10:55 UTC.
 */
class ExamNoShowServiceTest extends TestCase {

	private ExamParticipationRepository&MockObject $participations;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamSessionRepository&MockObject $sessions;
	private ExamOutbox&MockObject $outbox;
	private ExamTime&MockObject $time;
	private ExamNoShowService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->outbox         = $this->createMock( ExamOutbox::class );
		$this->time           = $this->createMock( ExamTime::class );

		$this->service = new ExamNoShowService( $this->participations, $this->registrations, $this->sessions, $this->outbox, $this->time );
	}

	private function participation( ?int $attemptId = null ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => 7, 'event_id' => 1, 'participant_id' => 4, 'audience' => 'student', 'current_attempt_id' => $attemptId,
			'transfer_allowed' => 0, 'version' => 3, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function registration( ?int $activeSlot = 1, ?string $arrivedAt = null ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => 20, 'participation_id' => 7, 'session_id' => 100, 'status' => 'confirmed', 'active_slot' => $activeSlot,
			'arrived_at' => $arrivedAt, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	private function session(): ExamSessionDTO {
		return ExamSessionDTO::fromArray( array(
			'id' => 100, 'event_id' => 1, 'assessment_id' => 50, 'scheduled_at' => '2026-03-12 07:00:00', 'planned_end_at' => '2026-03-12 10:55:00',
			'room_id' => 1, 'capacity' => 10, 'occupied_count' => 1, 'responsible_user_id' => 1, 'status' => 'open', 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	private function at( string $nowUtc ): void {
		$this->time->method( 'nowUtc' )->willReturn( $nowUtc );
	}

	public function test_missed_at_planned_end_without_attempt(): void {
		$this->at( '2026-03-12 10:55:00' );
		$this->registrations->expects( self::once() )->method( 'deactivate' )
			->with( 20, ExamRegistrationStatus::Missed, '2026-03-12 10:55:00' )->willReturn( true );

		self::assertTrue( $this->service->markMissedLocked( $this->participation(), $this->registration(), $this->session() ) );
	}

	public function test_not_missed_one_minute_before_planned_end(): void {
		$this->at( '2026-03-12 10:54:00' );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		self::assertFalse( $this->service->markMissedLocked( $this->participation(), $this->registration(), $this->session() ) );
	}

	public function test_not_missed_when_attempt_started(): void {
		// Начавший в 13:54 по Москве (10:54 UTC) продолжает: в 13:55 запись не меняется.
		$this->at( '2026-03-12 10:55:00' );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		self::assertFalse( $this->service->markMissedLocked( $this->participation( 9 ), $this->registration(), $this->session() ) );
	}

	public function test_missed_releases_seat_and_clears_active_registration_and_writes_outbox(): void {
		$this->at( '2026-03-12 11:30:00' );
		$this->registrations->method( 'deactivate' )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' )->with( 100 );
		$this->participations->expects( self::once() )->method( 'setActiveRegistration' )->with( 7, null );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::ParticipantMissed, 'participation', 7, 3, array( 'registration_id' => 20, 'session_id' => 100 )
		);

		$this->service->markMissedLocked( $this->participation(), $this->registration(), $this->session() );
	}

	public function test_second_run_does_nothing_and_writes_no_second_outbox_event(): void {
		$this->at( '2026-03-12 11:30:00' );
		// Запись уже закрыта (active_slot = NULL): повтор ничего не меняет и событие не пишет.
		$this->registrations->expects( self::never() )->method( 'deactivate' );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );
		$this->outbox->expects( self::never() )->method( 'add' );

		self::assertFalse( $this->service->markMissedLocked( $this->participation(), $this->registration( null ), $this->session() ) );
	}

	public function test_concurrent_close_of_the_same_registration_writes_no_event(): void {
		// Условный UPDATE проиграл гонку (запись закрыта соседним процессом) — место второй раз не освобождается.
		$this->at( '2026-03-12 11:30:00' );
		$this->registrations->method( 'deactivate' )->willReturn( false );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );
		$this->outbox->expects( self::never() )->method( 'add' );

		self::assertFalse( $this->service->markMissedLocked( $this->participation(), $this->registration(), $this->session() ) );
	}

	public function test_arrival_mark_does_not_prevent_missed(): void {
		$this->at( '2026-03-12 11:30:00' );
		$this->registrations->expects( self::once() )->method( 'deactivate' )->willReturn( true );

		self::assertTrue( $this->service->markMissedLocked( $this->participation(), $this->registration( 1, '2026-03-12 06:50:00' ), $this->session() ) );
	}

	public function test_mark_missed_locks_participation_before_rereading_the_registration(): void {
		// Под REPEATABLE READ перечитывание «под блокировкой» работает, только если блокировка — первый оператор транзакции.
		$this->at( '2026-03-12 11:30:00' );
		$order = array();
		$this->registrations->method( 'find' )->willReturnCallback( function () use ( &$order ): ExamRegistrationDTO {
			$order[] = 'registration';
			return $this->registration();
		} );
		$this->participations->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamParticipationDTO {
			$order[] = 'lock';
			return $this->participation();
		} );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->registrations->method( 'deactivate' )->willReturn( true );

		self::assertTrue( $this->service->markMissed( 20 ) );
		self::assertSame( array( 'registration', 'lock', 'registration' ), $order, 'участие определено до транзакции, затем блокировка, затем перечитывание' );
	}

	public function test_mark_missed_of_unknown_registration_is_false(): void {
		$this->registrations->method( 'find' )->willReturn( null );
		$this->participations->expects( self::never() )->method( 'findForUpdate' );

		self::assertFalse( $this->service->markMissed( 999 ) );
	}
}
