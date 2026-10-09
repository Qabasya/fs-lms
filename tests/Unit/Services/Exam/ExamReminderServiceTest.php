<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamNotificationComposer;
use Inc\Services\Exam\ExamReminderService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Напоминания: окна времени, пропуск поздних записей, повторная проверка записи, гость.
 * Сайт — Москва (+3). Сеанс — 2026-03-12 10:00 местного = 07:00 UTC.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamReminderServiceTest extends TestCase {

	use ExamFixtures;

	private ExamRegistrationRepository&MockObject $registrations;
	private ExamSessionRepository&MockObject $sessions;
	private ExamNotificationComposer&MockObject $composer;
	private string $nowUtc   = '2026-03-12 06:30:00';
	private string $nowLocal = '2026-03-12 09:30:00';
	private ExamReminderService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->registrations = $this->createMock( ExamRegistrationRepository::class );
		$this->sessions      = $this->createMock( ExamSessionRepository::class );
		$this->composer      = $this->createMock( ExamNotificationComposer::class );
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'scheduled_at' => '2026-03-12 07:00:00', 'planned_end_at' => '2026-03-12 10:55:00' ) ) );

		$time = $this->createMock( ExamTime::class );
		$time->method( 'nowUtc' )->willReturnCallback( fn (): string => $this->nowUtc );
		$time->method( 'nowLocal' )->willReturnCallback( fn (): string => $this->nowLocal );
		$time->method( 'addMinutes' )->willReturnCallback( static fn ( string $d, int $m ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' UTC' ) + $m * 60 ) );
		$time->method( 'toUtc' )->willReturnCallback( static fn ( string $l ): string => gmdate( 'Y-m-d H:i:s', strtotime( $l . ' UTC' ) - 3 * 3600 ) );
		$time->method( 'toLocal' )->willReturnCallback( static fn ( string $u ): string => gmdate( 'Y-m-d H:i:s', strtotime( $u . ' UTC' ) + 3 * 3600 ) );
		$time->method( 'endOfLocalDayUtc' )->willReturnCallback( static fn ( string $d ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' 23:59:59 UTC' ) - 3 * 3600 ) );

		$this->service = new ExamReminderService( $this->registrations, $this->sessions, $this->composer, $time );
	}

	/** @param array<string, mixed> $override */
	private function registration( array $override = array() ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array_merge( array(
			'id' => 20, 'participation_id' => 5, 'session_id' => 7, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 10:00:00',
		), $override ) );
	}

	private function given( ExamRegistrationDTO ...$list ): void {
		$this->registrations->method( 'listConfirmedStartingBetween' )->willReturn( array_values( $list ) );
		$this->registrations->method( 'find' )->willReturnCallback( static fn ( int $id ): ?ExamRegistrationDTO => current( array_filter( $list, static fn ( $r ) => $r->id === $id ) ) ?: null );
	}

	public function test_soon_sent_for_session_starting_within_60_minutes(): void {
		$this->given( $this->registration() );
		$this->composer->expects( self::once() )->method( 'remind' )->with( NotificationType::ExamSoon, 'soon', self::anything() )->willReturn( true );

		self::assertSame( 1, $this->service->soon() );
	}

	public function test_soon_skipped_for_registration_made_less_than_60_minutes_before_start(): void {
		$this->given( $this->registration( array( 'created_at' => '2026-03-12 06:10:00' ) ) );
		$this->composer->expects( self::never() )->method( 'remind' );

		self::assertSame( 0, $this->service->soon() );
	}

	public function test_tomorrow_sent_after_18_00_for_next_day_sessions(): void {
		$this->nowUtc   = '2026-03-11 15:30:00';
		$this->nowLocal = '2026-03-11 18:30:00';
		$this->given( $this->registration() );
		$this->composer->expects( self::once() )->method( 'remind' )->with( NotificationType::ExamTomorrow, 'tomorrow', self::anything() )->willReturn( true );

		self::assertSame( 1, $this->service->tomorrow() );
	}

	public function test_tomorrow_not_sent_before_18_00(): void {
		$this->nowLocal = '2026-03-11 17:59:00';
		$this->registrations->expects( self::never() )->method( 'listConfirmedStartingBetween' );

		self::assertSame( 0, $this->service->tomorrow() );
	}

	public function test_tomorrow_skipped_for_late_registration(): void {
		$this->nowUtc   = '2026-03-11 17:00:00';
		$this->nowLocal = '2026-03-11 20:00:00';
		// Запись сделана 11 марта в 19:00 местного (16:00 UTC) — после 18:00 накануне.
		$this->given( $this->registration( array( 'created_at' => '2026-03-11 16:00:00' ) ) );
		$this->composer->expects( self::never() )->method( 'remind' );

		self::assertSame( 0, $this->service->tomorrow() );
	}

	public function test_entry_opened_sent_once_at_session_start(): void {
		$this->nowUtc = '2026-03-12 07:01:00';
		$this->given( $this->registration() );
		$this->composer->expects( self::once() )->method( 'remind' )->with( NotificationType::ExamEntryOpened, 'entry', self::anything() )->willReturn( true );

		self::assertSame( 1, $this->service->entryOpened() );
	}

	public function test_entry_opened_skipped_for_long_started_session(): void {
		$this->nowUtc = '2026-03-12 07:30:00';
		$this->registrations->expects( self::once() )->method( 'listConfirmedStartingBetween' )->with( '2026-03-12 07:20:00', '2026-03-12 07:30:00' )->willReturn( array() );

		self::assertSame( 0, $this->service->entryOpened() );
	}

	public function test_cancelled_registration_gets_no_reminder(): void {
		// Запись попала в выборку, но к отправке её отменили: повторная проверка не пропускает.
		$this->registrations->method( 'listConfirmedStartingBetween' )->willReturn( array( $this->registration() ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration( array( 'status' => 'cancelled', 'active_slot' => null ) ) );
		$this->composer->expects( self::never() )->method( 'remind' );

		self::assertSame( 0, $this->service->soon() );
	}

	public function test_guest_registration_gets_no_reminder(): void {
		$this->given( $this->registration() );
		$this->composer->method( 'remind' )->willReturn( false ); // у гостя получателей нет

		self::assertSame( 0, $this->service->soon() );
	}

	public function test_changed_session_is_not_reminded_as_open(): void {
		$sessions = $this->createMock( ExamSessionRepository::class );
		$sessions->method( 'find' )->willReturn( $this->examSession( array( 'status' => 'cancelled' ) ) );
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( $this->nowUtc );
		$time->method( 'addMinutes' )->willReturn( '2026-03-12 07:30:00' );
		$service = new ExamReminderService( $this->registrations, $sessions, $this->composer, $time );
		$this->given( $this->registration() );
		$this->composer->expects( self::never() )->method( 'remind' );

		self::assertSame( 0, $service->soon() );
	}
}
