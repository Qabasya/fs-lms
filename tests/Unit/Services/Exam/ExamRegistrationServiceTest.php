<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Course\GroupLessonDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamOperationKeyDTO;
use Inc\DTO\Exam\RegistrationResultDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Exam\ExamAudience;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamOperationKeyRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\GroupLessonRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Запись, перенос и отмена: один порядок блокировок, одна проверка пересечений для записи и переноса,
 * защита от действий из устаревшей вкладки; правила записи (3.3) — по одному тесту на строку таблицы отказов, код проверяется
 * по `errorCode`, а не по тексту.
 *
 * Сайт — Москва (+3), «сейчас» — 2026-03-10 10:00 местного (07:00 UTC). Сеанс 100: 2026-03-12 07:00–10:55 UTC (10:00–13:55 МСК).
 */
#[AllowMockObjectsWithoutExpectations]
class ExamRegistrationServiceTest extends TestCase {

	private const EVENT         = 1;
	private const OLD_SESSION   = 100;
	private const NEW_SESSION   = 101;
	private const PARTICIPANT   = 4;
	private const PARTICIPATION = 7;
	private const REGISTRATION  = 20;
	private const PERSON        = 3;

	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamParticipantRepository&MockObject $participants;
	private ExamParticipationRepository&MockObject $participations;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamOperationKeyRepository&MockObject $operationKeys;
	private ExamAudienceResolver&MockObject $audience;
	private ExamAccessGuard&MockObject $accessGuard;
	private ExamOutbox&MockObject $outbox;
	private ExamGuestApplicationRepository&MockObject $guestApplications;
	private GroupLessonRepository&MockObject $lessons;
	private ExamRegistrationService $service;
	private ExamEventDTO $eventDto;
	private ?ExamOperationKeyDTO $storedOperation = null;
	/** @var int[] Идентификаторы, с которыми последний раз позвали lockInOrder. */
	private array $lockedSessionIds = array();

	protected function setUp(): void {
		parent::setUp();

		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->operationKeys  = $this->createMock( ExamOperationKeyRepository::class );
		$this->audience       = $this->createMock( ExamAudienceResolver::class );
		$this->accessGuard    = $this->createMock( ExamAccessGuard::class );
		$this->outbox         = $this->createMock( ExamOutbox::class );
		$this->guestApplications = $this->createMock( ExamGuestApplicationRepository::class );
		$this->lessons        = $this->createMock( GroupLessonRepository::class );

		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( static fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? '2026-03-10 07:00:00' : '2026-03-10 10:00:00' );

		$this->service = new ExamRegistrationService(
			$this->events, $this->sessions, $this->participants, $this->participations, $this->registrations,
			$this->operationKeys, $this->audience, $this->accessGuard, $this->outbox, new ExamTime( $clock ),
			$this->guestApplications, $this->lessons,
		);

		$this->eventDto = $this->event();
		$this->events->method( 'find' )->willReturnCallback( fn (): ExamEventDTO => $this->eventDto );
		$this->operationKeys->method( 'findByKey' )->willReturnCallback( fn (): ?ExamOperationKeyDTO => $this->storedOperation );
		$this->participants->method( 'findByPersonId' )->willReturn( $this->participant() );
		$this->participations->method( 'findByEventAndParticipant' )->willReturn( $this->participation() );
	}

	/** @param array<string, mixed> $override */
	private function event( array $override = array() ): ExamEventDTO {
		return ExamEventDTO::fromArray( array_merge( array(
			'id' => self::EVENT, 'subject_key' => 'inf', 'title' => 'Экзамен', 'owner_user_id' => 1, 'status' => 'published',
			'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'guest_registration_enabled' => 0, 'version' => 1,
			'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-11 00:00:00',
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		), $override ) );
	}

	private function session( int $id, string $start = '2026-03-12 07:00:00', string $end = '2026-03-12 10:55:00', string $status = 'open' ): ExamSessionDTO {
		return ExamSessionDTO::fromArray( array(
			'id' => $id, 'event_id' => self::EVENT, 'assessment_id' => 50, 'scheduled_at' => $start, 'planned_end_at' => $end,
			'room_id' => 1, 'capacity' => 10, 'occupied_count' => 1, 'responsible_user_id' => 1, 'status' => $status, 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	private function participant(): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array(
			'id' => self::PARTICIPANT, 'person_id' => self::PERSON, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	private function participation( int $version = 5, ?int $currentAttemptId = null, string $audience = 'student' ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => self::PARTICIPATION, 'event_id' => self::EVENT, 'participant_id' => self::PARTICIPANT, 'audience' => $audience,
			'current_attempt_id' => $currentAttemptId,
			'transfer_allowed' => 0, 'version' => $version, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		) );
	}

	private function registration(): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => self::REGISTRATION, 'participation_id' => self::PARTICIPATION, 'session_id' => self::OLD_SESSION,
			'status' => ExamRegistrationStatus::Confirmed->value, 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	/** Всё, что нужно переносу ученика с сеанса 100 на 101, кроме результата проверки пересечения. */
	private function arrangeChange( bool $overlaps ): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );
		$this->sessions->method( 'find' )->willReturn( $this->session( self::NEW_SESSION, '2026-03-12 11:00:00', '2026-03-12 14:55:00' ) );
		$this->sessions->method( 'lockInOrder' )->willReturnCallback( function ( array $ids ): array {
			$this->lockedSessionIds = $ids;
			return array(
				self::OLD_SESSION => $this->session( self::OLD_SESSION ),
				self::NEW_SESSION => $this->session( self::NEW_SESSION, '2026-03-12 11:00:00', '2026-03-12 14:55:00' ),
			);
		} );
		$this->registrations->method( 'hasOverlappingActive' )->willReturn( $overlaps );
	}

	public function test_move_is_refused_when_new_session_overlaps_registration_for_another_event(): void {
		$this->arrangeChange( true );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		try {
			$this->service->change( self::PERSON, self::NEW_SESSION, 'key-1' );
			self::fail( 'Перенос на пересекающееся время должен отказывать.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
		}
	}

	public function test_overlap_is_checked_against_the_new_session_and_the_current_event_is_excluded(): void {
		$this->arrangeChange( false );
		$this->registrations->expects( self::once() )->method( 'hasOverlappingActive' )
			->with( self::PARTICIPANT, '2026-03-12 11:00:00', '2026-03-12 14:55:00', self::EVENT )
			->willReturn( false );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'deactivate' )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 21 );

		$result = $this->service->change( self::PERSON, self::NEW_SESSION, 'key-2' );

		self::assertSame( 21, $result->registrationId );
	}

	public function test_staff_transfer_checks_overlap_too(): void {
		$this->arrangeChange( true );
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->accessGuard->method( 'canManageSubject' )->willReturn( true );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		try {
			$this->service->transferByStaff( 9, self::REGISTRATION, self::NEW_SESSION, 'причина' );
			self::fail( 'Перенос сотрудником на пересекающееся время должен отказывать.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
		}
	}

	/**
	 * Под REPEATABLE READ снимок данных создаёт первое обычное чтение транзакции. Если оно идёт ДО блокировки участника,
	 * проверка пересечений не увидит запись, зафиксированную другой транзакцией за время ожидания (найдено на реальной базе:
	 * два одновременных запроса занимали два пересекающихся сеанса). Поэтому блокировка — первый оператор.
	 */
	public function test_registration_takes_participant_lock_before_any_other_read_then_participation_then_session(): void {
		$order = array();
		$this->sessions->method( 'find' )->willReturnCallback( function () use ( &$order ): ExamSessionDTO {
			$order[] = 'read';
			return $this->session( self::OLD_SESSION );
		} );
		$this->participants->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamParticipantDTO {
			$order[] = 'participant';
			return $this->participant();
		} );
		$this->participations->method( 'getOrCreateLocked' )->willReturnCallback( function () use ( &$order ): ExamParticipationDTO {
			$order[] = 'participation';
			return $this->participation();
		} );
		$this->sessions->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamSessionDTO {
			$order[] = 'session';
			return $this->session( self::OLD_SESSION );
		} );
		$this->registrations->method( 'findActive' )->willReturn( null );
		$this->registrations->method( 'hasOverlappingActive' )->willReturn( false );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 55 );

		$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-3', null );

		self::assertSame( array( 'participant', 'read', 'participation', 'session' ), array_slice( $order, 0, 4 ) );
	}

	public function test_registration_is_refused_when_participant_is_missing(): void {
		$this->sessions->method( 'find' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->participants->method( 'findForUpdate' )->willReturn( null );
		$this->participations->expects( self::never() )->method( 'getOrCreateLocked' );

		try {
			$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-4', null );
			self::fail( 'Без участника записи быть не может.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_cancel_from_stale_tab_is_refused_with_stale_code(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 5 ) );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		try {
			$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-5', 4 );
			self::fail( 'Отмена по устаревшей версии должна отказывать.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamStale, $e->errorCode );
		}
	}

	public function test_change_from_stale_tab_is_refused_with_stale_code(): void {
		$this->sessions->method( 'find' )->willReturn( $this->session( self::NEW_SESSION ) );
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 5 ) );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		try {
			$this->service->change( self::PERSON, self::NEW_SESSION, 'key-6', 3 );
			self::fail( 'Перенос по устаревшей версии должен отказывать.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamStale, $e->errorCode );
		}
	}

	public function test_cancel_with_current_version_goes_through(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 5 ) );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::OLD_SESSION => $this->session( self::OLD_SESSION ) ) );
		$this->registrations->expects( self::once() )->method( 'deactivate' )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' );
		$this->participations->expects( self::once() )->method( 'setActiveRegistration' )->with( self::PARTICIPATION, null );

		$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-7', 5 );
	}

	public function test_cancel_without_expected_version_is_not_version_checked(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 9 ) );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::OLD_SESSION => $this->session( self::OLD_SESSION ) ) );
		$this->registrations->expects( self::once() )->method( 'deactivate' )->willReturn( true );

		$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-8' );
	}
	// ---- правила записи (3.3): по одному тесту на строку таблицы -------------------------------------------------------------------

	/** Всё, что нужно записи участника на сеанс 100, кроме проверяемого правила. */
	private function arrangeRegistration( ?ExamSessionDTO $session = null, ?ExamParticipationDTO $participation = null ): void {
		$session ??= $this->session( self::OLD_SESSION );
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->sessions->method( 'find' )->willReturn( $session );
		$this->sessions->method( 'findForUpdate' )->willReturn( $session );
		$this->participations->method( 'getOrCreateLocked' )->willReturn( $participation ?? $this->participation() );
		$this->registrations->method( 'findActive' )->willReturn( null );
		$this->registrations->method( 'hasOverlappingActive' )->willReturn( false );
		$this->registrations->method( 'insert' )->willReturn( 55 );
	}

	private function assertRegistrationRefused( ErrorCode $code, string $message, ?int $actor = null ): void {
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		try {
			$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-rule', $actor );
			self::fail( 'Ожидался отказ: ' . $message );
		} catch ( CodedException $e ) {
			self::assertSame( $code, $e->errorCode );
			self::assertSame( $message, $e->getMessage() );
		}
	}

	public function test_rule_event_not_published(): void {
		$this->arrangeRegistration();
		$this->eventDto = $this->event( array( 'status' => 'draft' ) );

		$this->assertRegistrationRefused( ErrorCode::ExamClosed, 'Запись на этот экзамен закрыта.' );
	}

	public function test_rule_session_cancelled(): void {
		$this->arrangeRegistration( $this->session( self::OLD_SESSION, '2026-03-12 07:00:00', '2026-03-12 10:55:00', 'cancelled' ) );

		$this->assertRegistrationRefused( ErrorCode::ExamClosed, 'Сеанс отменён.' );
	}

	public function test_rule_registration_not_open_yet(): void {
		$this->arrangeRegistration();
		$this->eventDto = $this->event( array( 'registration_opens_at' => '2026-03-10 07:00:01' ) );

		$this->assertRegistrationRefused( ErrorCode::ExamClosed, 'Запись ещё не открыта.' );
	}

	public function test_rule_registration_closed(): void {
		$this->arrangeRegistration();
		$this->eventDto = $this->event( array( 'registration_closes_at' => '2026-03-10 07:00:00' ) );

		$this->assertRegistrationRefused( ErrorCode::ExamClosed, 'Запись закрыта.' );
	}

	public function test_rule_session_already_started(): void {
		$this->arrangeRegistration( $this->session( self::OLD_SESSION, '2026-03-10 06:00:00', '2026-03-10 09:55:00' ) );

		$this->assertRegistrationRefused( ErrorCode::ExamClosed, 'Сеанс уже начался.' );
	}

	public function test_rule_staff_cannot_register_into_finished_session(): void {
		$this->arrangeRegistration( $this->session( self::OLD_SESSION, '2026-03-10 03:00:00', '2026-03-10 06:55:00' ) );

		$this->assertRegistrationRefused( ErrorCode::ExamClosed, 'Сеанс завершён.', 9 );
	}

	public function test_staff_may_register_into_running_session_outside_registration_window(): void {
		$this->arrangeRegistration( $this->session( self::OLD_SESSION, '2026-03-10 06:00:00', '2026-03-10 09:55:00' ) );
		$this->eventDto = $this->event( array( 'registration_closes_at' => '2026-03-09 00:00:00' ) );
		$this->sessions->method( 'occupySeat' )->willReturn( true );

		$result = $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-staff', 9 );

		self::assertSame( 55, $result->registrationId );
	}

	public function test_rule_attempt_exists(): void {
		$this->arrangeRegistration( null, $this->participation( 5, 77 ) );

		$this->assertRegistrationRefused( ErrorCode::ExamStarted, 'Экзамен уже начат или сдан.' );
	}

	public function test_rule_active_registration_exists(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->sessions->method( 'find' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->sessions->method( 'findForUpdate' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->participations->method( 'getOrCreateLocked' )->willReturn( $this->participation() );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );

		$this->assertRegistrationRefused( ErrorCode::ExamConflict, 'Запись на этот экзамен уже есть.' );
	}

	public function test_rule_overlap_with_other_event(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->sessions->method( 'find' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->sessions->method( 'findForUpdate' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->participations->method( 'getOrCreateLocked' )->willReturn( $this->participation() );
		$this->registrations->method( 'findActive' )->willReturn( null );
		$this->registrations->expects( self::once() )->method( 'hasOverlappingActive' )
			->with( self::PARTICIPANT, '2026-03-12 07:00:00', '2026-03-12 10:55:00', self::EVENT )->willReturn( true );

		$this->assertRegistrationRefused( ErrorCode::ExamConflict, 'В это время уже есть запись на другой экзамен.' );
	}

	public function test_same_variant_in_other_event_does_not_block(): void {
		// Сдача того же варианта в другом проведении расходует лимит того проведения: у ЭТОГО участия попытки нет.
		$this->arrangeRegistration( null, $this->participation( 5, null ) );
		$this->sessions->method( 'occupySeat' )->willReturn( true );

		$result = $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-variant', null );

		self::assertSame( 55, $result->registrationId );
	}

	public function test_full_session_without_holds_is_exam_full(): void {
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( false );
		$this->guestApplications->method( 'countHeldBySession' )->willReturn( 0 );

		try {
			$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-full', null );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamFull, $e->errorCode );
			self::assertSame( 'Свободных мест нет.', $e->getMessage() );
		}
	}

	public function test_full_with_active_holds_returns_exam_held(): void {
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( false );
		$this->guestApplications->expects( self::once() )->method( 'countHeldBySession' )->with( self::OLD_SESSION )->willReturn( 2 );
		$this->registrations->expects( self::never() )->method( 'insert' );

		try {
			$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-held', null );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamHeld, $e->errorCode );
			self::assertSame( 'Свободных мест сейчас нет: часть мест удерживается до оплаты. Попробуйте позже.', $e->getMessage() );
		}
	}

	public function test_move_into_full_session_with_holds_returns_exam_held_and_keeps_old_registration(): void {
		$this->arrangeChange( false );
		$this->sessions->method( 'occupySeat' )->willReturn( false );
		$this->guestApplications->method( 'countHeldBySession' )->willReturn( 1 );
		$this->registrations->expects( self::never() )->method( 'deactivate' );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );

		try {
			$this->service->change( self::PERSON, self::NEW_SESSION, 'key-move-held' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamHeld, $e->errorCode );
		}
	}

	// ---- предупреждение о занятиях (3.3.4) ----------------------------------------------------------------------------------------

	/** @param array<string, mixed> $override */
	private function lesson( string $start, ?string $end, array $override = array() ): GroupLessonDTO {
		return new GroupLessonDTO(
			id: 42, groupId: 5, lessonId: 1, position: 0, workIdsSnapshot: null, extraWorkIds: array(),
			scheduledAt: $start, endsAt: $end, isPinned: false, teacherUserId: null, visibility: 'open',
			openedAt: null, homeworkDueAt: null, allowLate: true, recordingUrl: null,
			createdByUserId: null, updatedByUserId: null, status: (string) ( $override['status'] ?? 'scheduled' ),
		);
	}

	/** Ученик, допущенный к экзамену, с занятиями группы 5 в день сеанса. @param GroupLessonDTO[] $lessons */
	private function arrangeStudentRegistration( array $lessons ): void {
		$this->audience->method( 'isEligible' )->willReturn( true );
		$this->audience->method( 'groupIdsForStudent' )->willReturn( array( 5 ) );
		$this->participants->method( 'getOrCreateForPerson' )->willReturn( self::PARTICIPANT );
		$this->lessons->method( 'listByGroupAndDay' )->with( 5, '2026-03-12' )->willReturn( $lessons );
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( true );
	}

	public function test_lesson_overlap_gives_warning_not_error(): void {
		// Сеанс 10:00–13:55 МСК; занятие 13:00–14:30 МСК пересекается.
		$this->arrangeStudentRegistration( array( $this->lesson( '2026-03-12 13:00:00', '2026-03-12 14:30:00' ) ) );

		$result = $this->service->register( self::PERSON, self::OLD_SESSION, 'key-lesson' );

		self::assertSame( 55, $result->registrationId, 'Запись оформлена несмотря на пересечение.' );
		self::assertSame( array( 'lesson_overlap' ), $result->warnings );
	}

	public function test_lesson_starting_exactly_at_session_end_does_not_overlap(): void {
		$this->arrangeStudentRegistration( array( $this->lesson( '2026-03-12 13:55:00', '2026-03-12 15:00:00' ) ) );

		self::assertSame( array(), $this->service->register( self::PERSON, self::OLD_SESSION, 'key-lesson-2' )->warnings );
	}

	public function test_lesson_without_end_is_assumed_to_last_an_hour(): void {
		$this->arrangeStudentRegistration( array( $this->lesson( '2026-03-12 09:30:00', null ) ) );

		self::assertSame( array( 'lesson_overlap' ), $this->service->register( self::PERSON, self::OLD_SESSION, 'key-lesson-3' )->warnings );
	}

	public function test_cancelled_lesson_gives_no_warning(): void {
		$this->arrangeStudentRegistration( array( $this->lesson( '2026-03-12 13:00:00', '2026-03-12 14:30:00', array( 'status' => 'cancelled' ) ) ) );

		self::assertSame( array(), $this->service->register( self::PERSON, self::OLD_SESSION, 'key-lesson-4' )->warnings );
	}

	// ---- подтверждение брони гостя (3.4.4) ------------------------------------------------------------------------------------------

	public function test_confirm_held_does_not_occupy_a_second_seat_and_ignores_registration_window(): void {
		$this->arrangeRegistration( null, $this->participation( 5, null, 'guest' ) );
		$this->eventDto = $this->event( array( 'registration_closes_at' => '2026-03-09 00:00:00' ) );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$this->participations->expects( self::once() )->method( 'setSource' )->with( self::PARTICIPATION, 14 );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			\Inc\Enums\Exam\ExamOutboxEvent::RegistrationConfirmed,
			'registration',
			55,
			1,
			self::callback( static fn ( array $p ): bool => 'guest' === $p['audience'] )
		);

		$result = $this->service->confirmHeld( self::PARTICIPANT, self::OLD_SESSION, 'app-9', 14 );

		self::assertSame( 55, $result->registrationId );
	}

	public function test_confirm_held_still_refuses_cancelled_session(): void {
		$this->arrangeRegistration( $this->session( self::OLD_SESSION, '2026-03-12 07:00:00', '2026-03-12 10:55:00', 'cancelled' ) );

		$this->expectException( CodedException::class );

		$this->service->confirmHeld( self::PARTICIPANT, self::OLD_SESSION, 'app-9', 14 );
	}

	public function test_confirm_held_does_not_open_its_own_transaction(): void {
		$original = $GLOBALS['wpdb'];
		$db       = new class() extends \wpdb {
			/** @var string[] */
			public array $log = array();

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $db;
		$this->arrangeRegistration( null, $this->participation( 5, null, 'guest' ) );

		try {
			$this->service->confirmHeld( self::PARTICIPANT, self::OLD_SESSION, 'app-9', 14 );
		} finally {
			$GLOBALS['wpdb'] = $original;
		}

		self::assertSame( array(), $db->log, 'Транзакцию открывает вызывающий (ExamHoldService::convert).' );
	}
	// ---- запись: место, идемпотентность, outbox (3.1) ----------------------------------------------------------------------------

	/** Подмена соединения: журнал границ транзакции вместе с обращениями мок-репозиториев. */
	private function recordingDb(): object {
		$db = new class() extends \wpdb {
			/** @var string[] */
			public array $log = array();
			public ?\wpdb $original = null;

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				return 1;
			}
		};
		$db->original    = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $db;

		return $db;
	}

	protected function tearDown(): void {
		if ( isset( $GLOBALS['wpdb']->original ) ) {
			$GLOBALS['wpdb'] = $GLOBALS['wpdb']->original;
		}
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	private function storedResult( string $operation, string $payload, ?RegistrationResultDTO $result = null ): ExamOperationKeyDTO {
		return ExamOperationKeyDTO::fromArray( array(
			'id' => 1, 'scope' => 'participant:' . self::PARTICIPANT, 'operation' => $operation, 'request_key' => 'key-r', 'payload_hash' => hash( 'sha256', $payload ),
			'result_ref' => null !== $result ? json_encode( $result->toArray() ) : 'ok', 'expires_at' => '2026-03-11 07:00:00', 'created_at' => '2026-03-10 07:00:00',
		) );
	}

	public function test_register_occupies_seat_and_creates_confirmed_registration(): void {
		$this->arrangeRegistration();
		$this->sessions->expects( self::once() )->method( 'occupySeat' )->with( self::OLD_SESSION )->willReturn( true );
		$this->registrations->expects( self::once() )->method( 'insert' )->with( self::callback( static fn ( array $row ): bool =>
			self::PARTICIPATION === $row['participation_id'] && self::OLD_SESSION === $row['session_id'] && 'confirmed' === $row['status']
			&& 1 === $row['active_slot'] && 'key-1' === $row['request_key']
		) )->willReturn( 55 );
		$this->participations->expects( self::once() )->method( 'setActiveRegistration' )->with( self::PARTICIPATION, 55 );

		$result = $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-1', null );

		self::assertSame( 55, $result->registrationId );
		self::assertSame( ExamRegistrationStatus::Confirmed, $result->status );
		self::assertFalse( $result->replayed );
	}

	public function test_register_returns_full_when_seat_not_occupied(): void {
		$this->arrangeRegistration();
		$this->sessions->expects( self::once() )->method( 'occupySeat' )->with( self::OLD_SESSION )->willReturn( false );
		$this->registrations->expects( self::never() )->method( 'insert' );
		$this->outbox->expects( self::never() )->method( 'add' );
		$this->operationKeys->expects( self::never() )->method( 'remember' );

		$this->assertRegistrationRefusedAfterSeat( ErrorCode::ExamFull, 'Свободных мест нет.' );
	}

	public function test_register_locks_participation_before_session(): void {
		$order = array();
		$this->participants->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamParticipantDTO {
			$order[] = 'participant';
			return $this->participant();
		} );
		$this->sessions->method( 'find' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->participations->method( 'getOrCreateLocked' )->willReturnCallback( function () use ( &$order ): ExamParticipationDTO {
			$order[] = 'participation';
			return $this->participation();
		} );
		$this->sessions->method( 'findForUpdate' )->willReturnCallback( function () use ( &$order ): ExamSessionDTO {
			$order[] = 'session';
			return $this->session( self::OLD_SESSION );
		} );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 55 );

		$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-2', null );

		self::assertSame( array( 'participant', 'participation', 'session' ), $order );
	}

	public function test_register_writes_outbox_inside_transaction(): void {
		$db = $this->recordingDb();
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::RegistrationConfirmed,
			'registration',
			55,
			1,
			array( 'event_id' => self::EVENT, 'session_id' => self::OLD_SESSION, 'participation_id' => self::PARTICIPATION, 'audience' => 'student' )
		)->willReturnCallback( static function () use ( $db ): void {
			$db->log[] = 'outbox';
		} );

		$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-3', null );

		self::assertSame( array( 'START TRANSACTION', 'outbox', 'COMMIT' ), $db->log );
	}

	public function test_failed_outbox_write_rolls_the_registration_back(): void {
		$db = $this->recordingDb();
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->outbox->method( 'add' )->willThrowException( new \RuntimeException( 'outbox недоступен' ) );

		try {
			$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-3b', null );
			self::fail( 'Ожидалось исключение.' );
		} catch ( \RuntimeException ) {
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log, 'Место и запись откатываются вместе с событием.' );
		}
	}

	public function test_same_request_key_returns_same_result_without_second_seat(): void {
		$first = new RegistrationResultDTO( 55, self::PARTICIPATION, self::OLD_SESSION, ExamRegistrationStatus::Confirmed, 8, false, array( 'lesson_overlap' ) );
		$this->storedOperation = $this->storedResult( 'register', (string) self::OLD_SESSION, $first );
		$this->arrangeRegistration();
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$this->registrations->expects( self::never() )->method( 'insert' );
		$this->outbox->expects( self::never() )->method( 'add' );

		$again = $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-r', null );

		self::assertSame( 55, $again->registrationId );
		self::assertTrue( $again->replayed );
		self::assertSame( array( 'lesson_overlap' ), $again->warnings, 'Предупреждение первого ответа повторяется вместе с ним.' );
	}

	public function test_same_request_key_with_other_session_is_rejected(): void {
		$this->storedOperation = $this->storedResult( 'register', (string) self::NEW_SESSION );
		$this->arrangeRegistration();
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		$this->assertRegistrationRefused( ErrorCode::ExamReplay, 'Повторите действие.' );
	}

	public function test_expired_operation_key_is_treated_as_new_operation(): void {
		$this->storedOperation = ExamOperationKeyDTO::fromArray( array(
			'id' => 1, 'scope' => 'participant:' . self::PARTICIPANT, 'operation' => 'register', 'request_key' => 'key-old',
			'payload_hash' => hash( 'sha256', 'другой сеанс' ), 'result_ref' => 'ok', 'expires_at' => '2026-03-10 06:59:59', 'created_at' => '2026-03-09 06:59:59',
		) );
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 55 );

		$result = $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-old', null );

		self::assertFalse( $result->replayed );
	}

	public function test_request_key_is_required_and_limited_to_64_chars(): void {
		foreach ( array( '', str_repeat( 'k', 65 ) ) as $key ) {
			try {
				$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, $key, null );
				self::fail( 'Ожидался отказ.' );
			} catch ( CodedException $e ) {
				self::assertSame( ErrorCode::ExamReplay, $e->errorCode );
			}
		}
	}

	public function test_ineligible_student_is_denied(): void {
		$this->audience->method( 'isEligible' )->willReturn( false );
		$this->sessions->method( 'find' )->willReturn( $this->session( self::OLD_SESSION ) );
		$this->participants->expects( self::never() )->method( 'getOrCreateForPerson' );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		try {
			$this->service->register( self::PERSON, self::OLD_SESSION, 'key-4' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
			self::assertSame( 'Экзамен недоступен для этого ученика.', $e->getMessage() );
		}
	}

	public function test_duplicate_key_is_reported_as_conflict_and_the_seat_is_given_back(): void {
		$this->arrangeRegistration();
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'insert' )->willThrowException( new DuplicateKeyException( "Duplicate entry '7-1' for key 'participation_active'" ) );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' )->with( self::OLD_SESSION );

		$this->assertRegistrationRefusedAfterSeat( ErrorCode::ExamConflict, 'Запись на этот экзамен уже есть.' );
	}

	/** Как {@see assertRegistrationRefused()}, но место уже занято — `occupySeat` не должен считаться «никогда не вызванным». */
	private function assertRegistrationRefusedAfterSeat( ErrorCode $code, string $message ): void {
		try {
			$this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-rule', null );
			self::fail( 'Ожидался отказ: ' . $message );
		} catch ( CodedException $e ) {
			self::assertSame( $code, $e->errorCode );
			self::assertSame( $message, $e->getMessage() );
		}
	}

	public function test_can_register_again_after_cancel(): void {
		// После отмены участие остаётся, действующей записи нет — новая запись создаётся тем же путём.
		$this->arrangeRegistration( null, $this->participation( 8, null ) );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->expects( self::once() )->method( 'insert' );

		self::assertSame( 55, $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-again', null )->registrationId );
	}

	public function test_can_register_again_after_missed(): void {
		$this->arrangeRegistration( null, $this->participation( 9, null ) );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->expects( self::once() )->method( 'insert' );

		self::assertSame( 55, $this->service->registerParticipant( self::PARTICIPANT, ExamAudience::Student, self::OLD_SESSION, 'key-after-miss', null )->registrationId );
	}

	// ---- перенос, отмена, перенос сотрудником (3.2) ------------------------------------------------------------------------------

	public function test_change_locks_sessions_in_ascending_id_order(): void {
		// Оба сеанса блокируются ОДНИМ вызовом lockInOrder, без поштучного findForUpdate: порядок «по возрастанию ID»
		// задаёт репозиторий (ExamSessionRepositoryTest), а не порядок «сначала старый, потом новый».
		$this->arrangeChange( false );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'deactivate' )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 21 );
		$this->sessions->expects( self::never() )->method( 'findForUpdate' );

		$this->service->change( self::PERSON, self::NEW_SESSION, 'key-c1' );

		self::assertSame( array( self::OLD_SESSION, self::NEW_SESSION ), $this->lockedSessionIds );
	}

	public function test_failed_change_keeps_old_registration_active(): void {
		$this->arrangeChange( false );
		$this->sessions->method( 'occupySeat' )->willReturn( false );
		$this->registrations->expects( self::never() )->method( 'deactivate' );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );
		$this->participations->expects( self::never() )->method( 'setActiveRegistration' );

		try {
			$this->service->change( self::PERSON, self::NEW_SESSION, 'key-c2' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamFull, $e->errorCode );
		}
	}

	public function test_successful_change_releases_old_seat_exactly_once(): void {
		$this->arrangeChange( false );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' )->with( self::OLD_SESSION );
		$this->registrations->expects( self::once() )->method( 'deactivate' )->with( self::REGISTRATION, ExamRegistrationStatus::Transferred, self::anything() )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 21 );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::RegistrationTransferred, 'registration', 21, 1, self::callback( static fn ( array $p ): bool =>
			self::OLD_SESSION === $p['old_session_id'] && self::NEW_SESSION === $p['new_session_id'] && 'self' === $p['by']
		) );

		$result = $this->service->change( self::PERSON, self::NEW_SESSION, 'key-c3' );

		self::assertSame( 21, $result->registrationId );
	}

	public function test_change_after_own_session_start_is_denied_for_student(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );
		$this->sessions->method( 'find' )->willReturn( $this->session( self::NEW_SESSION, '2026-03-12 11:00:00', '2026-03-12 14:55:00' ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array(
			self::OLD_SESSION => $this->session( self::OLD_SESSION, '2026-03-10 06:00:00', '2026-03-10 09:55:00' ), // уже начался
			self::NEW_SESSION => $this->session( self::NEW_SESSION, '2026-03-12 11:00:00', '2026-03-12 14:55:00' ),
		) );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		try {
			$this->service->change( self::PERSON, self::NEW_SESSION, 'key-c4' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamClosed, $e->errorCode );
		}
	}

	private function arrangeSelfCancel( string $oldStart, string $oldEnd ): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::OLD_SESSION => $this->session( self::OLD_SESSION, $oldStart, $oldEnd ) ) );
	}

	public function test_self_cancel_before_start_releases_seat(): void {
		$this->arrangeSelfCancel( '2026-03-12 07:00:00', '2026-03-12 10:55:00' );
		$this->registrations->expects( self::once() )->method( 'deactivate' )->with( self::REGISTRATION, ExamRegistrationStatus::Cancelled, self::anything(), null, null )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' )->with( self::OLD_SESSION );
		$this->participations->expects( self::once() )->method( 'setActiveRegistration' )->with( self::PARTICIPATION, null );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::RegistrationCancelled, 'registration', self::REGISTRATION, 1, self::callback( static fn ( array $p ): bool => 'self' === $p['by'] ) );

		$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-x1' );
	}

	public function test_self_cancel_after_start_is_denied(): void {
		$this->arrangeSelfCancel( '2026-03-10 06:00:00', '2026-03-10 09:55:00' );
		$this->registrations->expects( self::never() )->method( 'deactivate' );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );

		try {
			$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-x2' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamClosed, $e->errorCode );
		}
	}

	public function test_self_cancel_is_refused_once_the_attempt_exists(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 5, 77 ) );
		$this->registrations->method( 'findActive' )->willReturn( $this->registration() );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::OLD_SESSION => $this->session( self::OLD_SESSION ) ) );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		try {
			$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-x3' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamStarted, $e->errorCode );
		}
	}

	public function test_cancel_is_idempotent_by_request_key(): void {
		$this->storedOperation = $this->storedResult( 'cancel', (string) self::EVENT );
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->expects( self::never() )->method( 'deactivate' );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );
		$this->outbox->expects( self::never() )->method( 'add' );

		$this->service->cancelBySelf( self::PERSON, self::EVENT, 'key-r' );
		$this->addToAssertionCount( 1 );
	}

	private function arrangeStaffScope( bool $canManage ): void {
		$this->registrations->method( 'find' )->willReturn( $this->registration() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->accessGuard->method( 'canManageSubject' )->willReturn( $canManage );
	}

	public function test_staff_cancel_requires_reason(): void {
		$this->arrangeStaffScope( true );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Укажите причину отмены.' );

		$this->service->cancelByStaff( 9, self::REGISTRATION, '   ' );
	}

	public function test_staff_cancel_denied_when_attempt_started(): void {
		$this->arrangeStaffScope( true );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation( 5, 77 ) );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		try {
			$this->service->cancelByStaff( 9, self::REGISTRATION, 'Болеет' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamStarted, $e->errorCode );
			self::assertSame( 'Попытка уже начата: запись отменить нельзя.', $e->getMessage() );
		}
	}

	public function test_staff_cancel_requires_event_scope(): void {
		$this->arrangeStaffScope( false );
		$this->registrations->expects( self::never() )->method( 'deactivate' );

		try {
			$this->service->cancelByStaff( 9, self::REGISTRATION, 'Болеет' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_staff_cancel_is_allowed_after_session_start_while_no_attempt(): void {
		$this->arrangeStaffScope( true );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::OLD_SESSION => $this->session( self::OLD_SESSION, '2026-03-10 06:00:00', '2026-03-10 09:55:00' ) ) );
		$this->registrations->expects( self::once() )->method( 'deactivate' )->with( self::REGISTRATION, ExamRegistrationStatus::Cancelled, self::anything(), 'Болеет', 9 )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::RegistrationCancelled, 'registration', self::REGISTRATION, 1, self::callback( static fn ( array $p ): bool => 'staff' === $p['by'] && 'Болеет' === $p['reason'] ) );

		$this->service->cancelByStaff( 9, self::REGISTRATION, ' Болеет ' );
	}

	public function test_staff_transfer_allowed_after_session_start_without_attempt(): void {
		$this->arrangeStaffScope( true );
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findForUpdate' )->willReturn( $this->participation() );
		$this->registrations->method( 'hasOverlappingActive' )->willReturn( false );
		$this->sessions->method( 'lockInOrder' )->willReturn( array(
			self::OLD_SESSION => $this->session( self::OLD_SESSION, '2026-03-10 06:00:00', '2026-03-10 09:55:00' ), // начался
			self::NEW_SESSION => $this->session( self::NEW_SESSION, '2026-03-10 07:30:00', '2026-03-10 11:25:00' ), // идёт сейчас: окно записи закрыто, сотруднику можно
		) );
		$this->eventDto = $this->event( array( 'registration_closes_at' => '2026-03-09 00:00:00' ) );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->registrations->method( 'deactivate' )->willReturn( true );
		$this->registrations->method( 'insert' )->willReturn( 22 );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::RegistrationTransferred, 'registration', 22, 1, self::callback( static fn ( array $p ): bool => 'staff' === $p['by'] && 'Просьба родителей' === $p['reason'] ) );

		$result = $this->service->transferByStaff( 9, self::REGISTRATION, self::NEW_SESSION, 'Просьба родителей' );

		self::assertSame( 22, $result->registrationId );
	}

	public function test_staff_transfer_requires_reason(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->service->transferByStaff( 9, self::REGISTRATION, self::NEW_SESSION, '' );
	}

	public function test_history_lists_registrations_of_participation(): void {
		$this->registrations->method( 'findByParticipation' )->with( self::PARTICIPATION )->willReturn( array( $this->registration() ) );

		$history = $this->service->history( self::PARTICIPATION );

		self::assertCount( 1, $history );
		self::assertSame( self::REGISTRATION, $history[0]['registration_id'] );
		self::assertSame( 'confirmed', $history[0]['status'] );
		self::assertFalse( ( new \ReflectionClass( ExamRegistrationRepository::class ) )->hasMethod( 'delete' ), 'Строки записей не удаляются ни одной операцией.' );
	}
}
