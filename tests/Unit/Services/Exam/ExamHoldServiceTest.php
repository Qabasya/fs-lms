<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\DTO\Exam\RegistrationResultDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Exam\ExamRegistrationStatus;
use Inc\Enums\Exam\GuestApplicationState;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\DuplicateKeyException;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamTime;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Бронь гостя: место занимается и освобождается через ту же вместимость, что и запись ученика, ровно один раз.
 *
 * «Сейчас» — 2026-03-10 07:00 UTC. Сеанс 7: 2026-03-12 07:00–10:55 UTC. Окно записи — до 2026-03-11 00:00 UTC.
 * Журнал `$db->log` фиксирует границы транзакции и порядок обращений.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamHoldServiceTest extends TestCase {

	use ExamFixtures;

	private const SESSION     = 7;
	private const EVENT       = 3;
	private const SOURCE      = 14;
	private const APPLICATION = 9;

	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamSessionRepository&MockObject $sessions;
	private ExamEventRepository&MockObject $events;
	private ExamParticipantRepository&MockObject $participants;
	private \Inc\Repositories\WPDBRepositories\ExamParticipationRepository&MockObject $participationsRepo;
	private \Inc\Repositories\WPDBRepositories\ExamManualResolutionRepository&MockObject $resolutionsRepo;
	private ExamRegistrationService&MockObject $registrations;
	private ExamOutbox&MockObject $outbox;
	private ExamHoldService $service;
	private object $db;
	private \wpdb $originalWpdb;

	// Сценарий теста.
	private ExamEventDTO $event;
	private ExamSessionDTO $session;
	private ExamGuestApplicationDTO $application;
	/** @var array<int, ExamGuestApplicationDTO> Заявки по ID, если тесту нужны разные. */
	private array $applicationsById = array();
	/** @var array<string, mixed> */
	private array $inserted = array();
	/** @var list<array{id: int, data: array<string, mixed>, version: int}> */
	private array $updates = array();

	protected function setUp(): void {
		parent::setUp();

		$this->originalWpdb = $GLOBALS['wpdb'];
		$this->db           = new class() extends \wpdb {
			/** @var string[] */
			public array $log = array();

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $this->db;

		$this->event       = $this->examEvent( array(
			'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-11 00:00:00',
		) );
		$this->session     = $this->examSession( array( 'scheduled_at' => '2026-03-12 07:00:00', 'planned_end_at' => '2026-03-12 10:55:00' ) );
		$this->application = $this->examGuestApplication();

		$this->applications  = $this->createMock( ExamGuestApplicationRepository::class );
		$this->sessions      = $this->createMock( ExamSessionRepository::class );
		$this->events        = $this->createMock( ExamEventRepository::class );
		$this->participants  = $this->createMock( ExamParticipantRepository::class );
		$this->participationsRepo = $this->createMock( \Inc\Repositories\WPDBRepositories\ExamParticipationRepository::class );
		$this->resolutionsRepo    = $this->createMock( \Inc\Repositories\WPDBRepositories\ExamManualResolutionRepository::class );
		$this->registrations = $this->createMock( ExamRegistrationService::class );
		$this->outbox        = $this->createMock( ExamOutbox::class );

		$this->events->method( 'find' )->willReturnCallback( fn (): ExamEventDTO => $this->event );
		$this->sessions->method( 'findForUpdate' )->willReturnCallback( function (): ExamSessionDTO {
			$this->db->log[] = 'session-lock';
			return $this->session;
		} );
		$this->sessions->method( 'occupySeat' )->willReturn( true );
		$this->applications->method( 'findForUpdate' )->willReturnCallback( function ( int $id ): ExamGuestApplicationDTO {
			$this->db->log[] = 'application-lock';
			return $this->applicationsById[ $id ] ?? $this->application;
		} );
		$this->applications->method( 'find' )->willReturnCallback( fn (): ExamGuestApplicationDTO => $this->application );
		$this->applications->method( 'insert' )->willReturnCallback( function ( array $row ): int {
			$this->inserted = $row;
			return self::APPLICATION;
		} );
		$this->applications->method( 'update' )->willReturnCallback( function ( int $id, array $data, int $version ): bool {
			$this->updates[] = array( 'id' => $id, 'data' => $data, 'version' => $version );
			return true;
		} );
		$this->participants->method( 'insert' )->willReturn( 31 );

		$this->service = $this->makeService();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->originalWpdb;
		parent::tearDown();
	}

	private function makeService(): ExamHoldService {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( static fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? '2026-03-10 07:00:00' : '2026-03-10 10:00:00' );

		return new ExamHoldService( $this->applications, $this->sessions, $this->events, $this->participants, $this->registrations, $this->outbox, new ExamTime( $clock ),
			new \Inc\Services\Exam\GuestParticipantMaterializer( $this->participants, $this->createMock( \Inc\Services\Security\PiiCryptoService::class ), $this->createMock( \Inc\Services\Exam\GuestIdentity::class ), new ExamTime( $clock ) ),
			$this->participationsRepo,
			$this->resolutionsRepo
		);
	}

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function captureData( array $override = array() ): array {
		return array_merge( array(
			'event_id' => self::EVENT, 'session_id' => self::SESSION, 'source_id' => self::SOURCE, 'identity_hash' => str_repeat( 'a', 64 ),
			'request_key' => 'req-1', 'draft_enc' => 'enc', 'source_snapshot' => '{"school_name":"Школа 5","grade":9}', 'consent_refs' => '[1,2]', 'ip_hash' => str_repeat( 'b', 64 ),
		), $override );
	}

	private function assertRefusal( ErrorCode $code, callable $call, ?string $message = null ): void {
		try {
			$call();
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( $code, $e->errorCode );
			if ( null !== $message ) {
				self::assertSame( $message, $e->getMessage() );
			}
		}
	}

	private function registrationResult(): RegistrationResultDTO {
		return new RegistrationResultDTO( 55, 41, self::SESSION, ExamRegistrationStatus::Confirmed, 3 );
	}

	// ---- оформление брони ----------------------------------------------------------------------------------------------------------

	public function test_capture_occupies_seat_and_sets_expiry_by_ttl(): void {
		$this->sessions->expects( self::once() )->method( 'occupySeat' )->with( self::SESSION )->willReturn( true );

		$this->service->capture( $this->captureData(), 20 );

		self::assertSame( '2026-03-10 07:20:00', $this->inserted['hold_expires_at'] );
		self::assertSame( 'hold', $this->inserted['state'] );
		self::assertSame( 1, $this->inserted['is_held'] );
		self::assertSame( 1, $this->inserted['active_slot'] );
		self::assertSame( 'req-1', $this->inserted['request_key'] );
		self::assertSame( 'enc', $this->inserted['draft_enc'] );
		self::assertSame( str_repeat( 'b', 64 ), $this->inserted['ip_hash'] );
		self::assertArrayNotHasKey( 'created_by_user_id', $this->inserted );
	}

	public function test_capture_takes_session_lock_as_first_statement_and_commits(): void {
		$this->service->capture( $this->captureData(), 20 );

		self::assertSame( array( 'START TRANSACTION', 'session-lock', 'COMMIT' ), $this->db->log );
	}

	public function test_expiry_is_capped_by_registration_close(): void {
		$this->event = $this->examEvent( array(
			'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-10 07:10:00',
		) );

		$this->service->capture( $this->captureData(), 20 );

		self::assertSame( '2026-03-10 07:10:00', $this->inserted['hold_expires_at'] );
	}

	public function test_expiry_is_capped_by_session_start(): void {
		$this->session = $this->examSession( array( 'scheduled_at' => '2026-03-10 07:05:00', 'planned_end_at' => '2026-03-10 10:55:00' ) );

		$this->service->capture( $this->captureData(), 20 );

		self::assertSame( '2026-03-10 07:05:00', $this->inserted['hold_expires_at'] );
	}

	public function test_expiry_is_capped_by_registration_close_and_session_start(): void {
		// Оба ограничителя действуют вместе: срок брони — ближайший из «TTL» (сейчас 07:00 + 20 минут), «закрытие записи», «начало сеанса».
		$cases = array(
			'закрытие записи раньше' => array( '2026-03-10 07:10:00', '2026-03-10 07:15:00', '2026-03-10 07:10:00' ),
			'начало сеанса раньше'   => array( '2026-03-10 07:18:00', '2026-03-10 07:12:00', '2026-03-10 07:12:00' ),
			'одновременно'           => array( '2026-03-10 07:05:00', '2026-03-10 07:05:00', '2026-03-10 07:05:00' ),
			'оба позже TTL'          => array( '2026-03-10 07:40:00', '2026-03-10 07:50:00', '2026-03-10 07:20:00' ),
		);
		foreach ( $cases as $label => list( $closes, $starts, $expected ) ) {
			$this->event   = $this->examEvent( array(
				'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => $closes,
			) );
			$this->session = $this->examSession( array( 'scheduled_at' => $starts, 'planned_end_at' => '2026-03-10 11:00:00' ) );

			$this->service->capture( $this->captureData(), 20 );

			self::assertSame( $expected, $this->inserted['hold_expires_at'], $label );
		}
	}
	public function test_staff_on_site_expiry_is_capped_by_planned_end(): void {
		// Сеанс уже идёт, окно записи закрыто — сотруднику на месте это не мешает; бронь не дольше конца сеанса.
		$this->session = $this->examSession( array( 'scheduled_at' => '2026-03-10 06:00:00', 'planned_end_at' => '2026-03-10 07:10:00' ) );
		$this->event   = $this->examEvent( array(
			'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-09 00:00:00',
		) );

		$this->service->capture( $this->captureData( array( 'created_by_user_id' => 9 ) ), 20 );

		self::assertSame( '2026-03-10 07:10:00', $this->inserted['hold_expires_at'] );
		self::assertSame( 9, $this->inserted['created_by_user_id'] );
	}

	public function test_staff_cannot_hold_for_finished_session(): void {
		$this->session = $this->examSession( array( 'scheduled_at' => '2026-03-10 03:00:00', 'planned_end_at' => '2026-03-10 06:55:00' ) );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		$this->assertRefusal( ErrorCode::ExamClosed, fn () => $this->service->capture( $this->captureData( array( 'created_by_user_id' => 9 ) ), 20 ), 'Сеанс завершён.' );
	}

	public function test_capture_with_same_request_key_returns_same_application_without_second_seat(): void {
		$this->applications->method( 'findBySourceAndRequestKey' )->with( self::SOURCE, 'req-1' )->willReturn( $this->application );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$this->applications->expects( self::never() )->method( 'insert' );

		$result = $this->service->capture( $this->captureData(), 20 );

		self::assertSame( $this->application, $result );
		self::assertSame( '2026-03-10 07:20:00', $result->holdExpiresAt, 'Бронь не продлевается.' );
	}

	public function test_same_request_key_with_other_session_or_identity_is_replay_error(): void {
		$this->applications->method( 'findBySourceAndRequestKey' )->willReturn( $this->examGuestApplication( array( 'identity_hash' => str_repeat( 'c', 64 ) ) ) );

		$this->assertRefusal( ErrorCode::ExamReplay, fn () => $this->service->capture( $this->captureData(), 20 ) );
	}

	public function test_capture_releases_expired_holds_of_session_first(): void {
		$order = array();
		$this->applicationsById = array( 5 => $this->examGuestApplication( array( 'id' => '5' ) ), 6 => $this->examGuestApplication( array( 'id' => '6' ) ) );
		$this->applications->method( 'listExpiredHeldIdsBySession' )->with( self::SESSION, '2026-03-10 07:00:00' )->willReturn( array( 5, 6 ) );
		$this->applications->method( 'releaseHeldFlag' )->willReturnCallback( function ( int $id ) use ( &$order ): bool {
			$order[] = "release-{$id}";
			return true;
		} );
		$this->sessions->method( 'releaseSeat' )->willReturnCallback( function () use ( &$order ): bool {
			$order[] = 'seat-back';
			return true;
		} );
		$this->sessions->method( 'occupySeat' )->willReturnCallback( function () use ( &$order ): bool {
			$order[] = 'occupy';
			return true;
		} );

		$this->service->capture( $this->captureData(), 20 );

		self::assertSame( array( 'release-5', 'seat-back', 'release-6', 'seat-back', 'occupy' ), $order );
	}

	public function test_capture_full_session_throws_exam_full(): void {
		$sessions = $this->createMock( ExamSessionRepository::class );
		$sessions->method( 'findForUpdate' )->willReturn( $this->session );
		$sessions->method( 'occupySeat' )->willReturn( false );
		$this->sessions = $sessions;
		$service        = $this->makeService();
		$this->applications->expects( self::never() )->method( 'insert' );

		$this->assertRefusal( ErrorCode::ExamFull, fn () => $service->capture( $this->captureData(), 20 ), 'Свободных мест нет.' );
		self::assertContains( 'ROLLBACK', $this->db->log );
	}

	public function test_second_active_application_of_same_identity_is_conflict_without_leaking_data(): void {
		$applications = $this->createMock( ExamGuestApplicationRepository::class );
		$applications->method( 'insert' )->willThrowException( new DuplicateKeyException( "Duplicate entry '3-" . str_repeat( 'a', 64 ) . "-1' for key 'identity_active'" ) );
		$this->applications = $applications;
		$service            = $this->makeService();

		try {
			$service->capture( $this->captureData(), 20 );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
			self::assertSame( 'Заявка на этот экзамен уже оформлена.', $e->getMessage() );
			self::assertStringNotContainsString( str_repeat( 'a', 8 ), $e->getMessage() );
		}
		self::assertContains( 'ROLLBACK', $this->db->log, 'Место, занятое этой попыткой, откатывается вместе с транзакцией.' );
	}

	public function test_duplicate_source_key_race_is_replay_error(): void {
		$applications = $this->createMock( ExamGuestApplicationRepository::class );
		$applications->method( 'insert' )->willThrowException( new DuplicateKeyException( "Duplicate entry '14-req-1' for key 'source_request'" ) );
		$this->applications = $applications;

		$this->assertRefusal( ErrorCode::ExamReplay, fn () => $this->makeService()->capture( $this->captureData(), 20 ) );
	}

	public function test_capture_is_refused_when_guest_registration_is_disabled(): void {
		$this->event = $this->examEvent( array( 'status' => 'published', 'guest_registration_enabled' => '0' ) );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		$this->assertRefusal( ErrorCode::ExamClosed, fn () => $this->service->capture( $this->captureData(), 20 ), 'Запись для гостей закрыта.' );
	}

	public function test_capture_respects_registration_window_and_session_start(): void {
		$this->event = $this->examEvent( array( 'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-10 07:00:01', 'registration_closes_at' => '2026-03-11 00:00:00' ) );
		$this->assertRefusal( ErrorCode::ExamClosed, fn () => $this->service->capture( $this->captureData(), 20 ), 'Запись ещё не открыта.' );

		$this->event = $this->examEvent( array( 'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-10 07:00:00' ) );
		$this->assertRefusal( ErrorCode::ExamClosed, fn () => $this->service->capture( $this->captureData(), 20 ), 'Запись закрыта.' );

		$this->event   = $this->examEvent( array( 'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-11 00:00:00' ) );
		$this->session = $this->examSession( array( 'scheduled_at' => '2026-03-10 07:00:00', 'planned_end_at' => '2026-03-10 10:55:00' ) );
		$this->assertRefusal( ErrorCode::ExamClosed, fn () => $this->service->capture( $this->captureData(), 20 ), 'Сеанс уже начался.' );
	}

	public function test_capture_is_refused_for_session_of_another_event(): void {
		$this->assertRefusal( ErrorCode::ExamClosed, fn () => $this->service->capture( $this->captureData( array( 'event_id' => 99 ) ), 20 ), 'Сеанс не найден.' );
	}

	public function test_capture_requires_identity_and_key(): void {
		$this->assertRefusal( ErrorCode::ExamReplay, fn () => $this->service->capture( $this->captureData( array( 'request_key' => '' ) ), 20 ) );
		$this->assertRefusal( ErrorCode::ExamReplay, fn () => $this->service->capture( $this->captureData( array( 'identity_hash' => '' ) ), 20 ) );
		$this->assertRefusal( ErrorCode::ExamReplay, fn () => $this->service->capture( $this->captureData(), 0 ) );
	}

	// ---- подтверждение оплаты ------------------------------------------------------------------------------------------------------

	public function test_convert_live_hold_does_not_occupy_second_seat(): void {
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$this->registrations->expects( self::never() )->method( 'confirmLate' );
		$this->registrations->expects( self::once() )->method( 'confirmHeld' )->with( 31, self::SESSION, 'app-9', self::SOURCE, null )->willReturn( $this->registrationResult() );

		$this->service->convert( self::APPLICATION, null );

		$final = $this->updates[ array_key_last( $this->updates ) ];
		self::assertSame( 'confirmed', $final['data']['state'] );
		self::assertSame( 0, $final['data']['is_held'] );
		self::assertSame( 1, $final['data']['active_slot'] );
		self::assertSame( 31, $final['data']['participant_id'] );
		self::assertSame( 41, $final['data']['participation_id'] );
		self::assertSame( 55, $final['data']['registration_id'] );
		self::assertSame( 1, $final['version'], 'Версия, прочитанная под блокировкой заявки.' );
	}

	public function test_convert_takes_application_lock_first_and_creates_participant_from_source_snapshot(): void {
		$this->application = $this->examGuestApplication( array( 'source_snapshot' => '{"school_name":"Школа 5","school_key":"s5","grade":9}' ) );
		$this->participants->expects( self::once() )->method( 'insert' )->with( self::callback( static fn ( array $row ): bool => 'Школа 5' === $row['school_name'] && 's5' === $row['school_key'] && 9 === $row['grade'] && ! isset( $row['name_enc'] ) ) )->willReturn( 31 );
		$this->registrations->method( 'confirmHeld' )->willReturn( $this->registrationResult() );

		$this->service->convert( self::APPLICATION, null );

		self::assertSame( array( 'START TRANSACTION', 'application-lock', 'COMMIT' ), $this->db->log );
	}

	public function test_convert_copies_transfer_consent_to_participation(): void {
		$this->application = $this->examGuestApplication( array( 'consent_refs' => '{"pd_processing":11,"pd_transfer":12}' ) );
		$this->registrations->method( 'confirmHeld' )->willReturn( $this->registrationResult() );
		$this->participationsRepo->expects( self::once() )->method( 'setConsent' )->with( 41, '{"pd_processing":11,"pd_transfer":12}', true );

		$this->service->convert( self::APPLICATION, null );
	}

	public function test_convert_without_transfer_consent_keeps_transfer_closed(): void {
		$this->application = $this->examGuestApplication( array( 'consent_refs' => '{"pd_processing":11}' ) );
		$this->registrations->method( 'confirmHeld' )->willReturn( $this->registrationResult() );
		$this->participationsRepo->expects( self::once() )->method( 'setConsent' )->with( 41, '{"pd_processing":11}', false );

		$this->service->convert( self::APPLICATION, null );
	}

	public function test_convert_reuses_existing_participant(): void {
		$this->application = $this->examGuestApplication( array( 'participant_id' => '31' ) );
		$this->participants->expects( self::never() )->method( 'insert' );
		$this->registrations->method( 'confirmHeld' )->willReturn( $this->registrationResult() );

		$this->service->convert( self::APPLICATION, null );
		$this->addToAssertionCount( 1 );
	}

	public function test_convert_expired_hold_with_free_seat_occupies_and_confirms(): void {
		$this->application = $this->examGuestApplication( array( 'is_held' => '0', 'active_slot' => null, 'state' => 'expired_unpaid' ) );
		$this->applications->method( 'hasActiveByIdentity' )->willReturn( false );
		$this->registrations->expects( self::never() )->method( 'confirmHeld' );
		$this->registrations->expects( self::once() )->method( 'confirmLate' )->with( 31, self::SESSION, 'app-9', self::SOURCE, 77 )->willReturn( $this->registrationResult() );

		$this->outbox->expects( self::never() )->method( 'add' );

		$this->service->convert( self::APPLICATION, 77 );

		$final = $this->updates[ array_key_last( $this->updates ) ];
		self::assertSame( 'confirmed', $final['data']['state'] );
		self::assertSame( 1, $final['data']['active_slot'], 'Активный слот возвращается: место теперь занято записью.' );
	}

	public function test_convert_expired_hold_without_seat_becomes_paid_needs_resolution_and_writes_outbox(): void {
		$this->application = $this->examGuestApplication( array( 'is_held' => '0', 'active_slot' => null, 'state' => 'expired_unpaid', 'version' => '4' ) );
		$this->applications->method( 'hasActiveByIdentity' )->willReturn( false );
		$this->registrations->method( 'confirmLate' )->willThrowException( new CodedException( ErrorCode::ExamFull, 'Свободных мест нет.' ) );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::PaidNeedsResolution,
			'guest_application',
			self::APPLICATION,
			5,
			array( 'application_id' => self::APPLICATION, 'event_id' => self::EVENT, 'session_id' => self::SESSION )
		);

		$this->service->convert( self::APPLICATION, null );

		$final = $this->updates[ array_key_last( $this->updates ) ];
		self::assertSame( 'paid_needs_resolution', $final['data']['state'] );
		self::assertSame( 4, $final['version'] );
		self::assertNotContains( 'ROLLBACK', $this->db->log, 'Оплата не теряется: заявка помечается и транзакция фиксируется.' );
	}

	public function test_convert_with_identity_slot_taken_by_new_application_needs_resolution_without_placing(): void {
		$this->application = $this->examGuestApplication( array( 'is_held' => '0', 'active_slot' => null, 'state' => 'expired_unpaid' ) );
		$this->applications->method( 'hasActiveByIdentity' )->willReturn( true );
		$this->registrations->expects( self::never() )->method( 'confirmLate' );
		$this->outbox->expects( self::once() )->method( 'add' );

		$this->service->convert( self::APPLICATION, null );

		self::assertSame( 'paid_needs_resolution', $this->updates[ array_key_last( $this->updates ) ]['data']['state'] );
	}

	public function test_convert_live_hold_that_cannot_be_placed_gives_the_seat_back_and_needs_resolution(): void {
		$this->registrations->method( 'confirmHeld' )->willThrowException( new CodedException( ErrorCode::ExamClosed, 'Сеанс отменён.' ) );
		$this->applications->expects( self::once() )->method( 'releaseHeldFlag' )->with( self::APPLICATION )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' )->with( self::SESSION );
		$this->outbox->expects( self::once() )->method( 'add' );

		$this->service->convert( self::APPLICATION, null );

		self::assertSame( 'paid_needs_resolution', $this->updates[ array_key_last( $this->updates ) ]['data']['state'] );
	}

	public function test_convert_confirmed_is_noop(): void {
		$this->application = $this->examGuestApplication( array( 'state' => 'confirmed', 'is_held' => '0', 'registration_id' => '55' ) );
		$this->registrations->expects( self::never() )->method( 'confirmHeld' );
		$this->registrations->expects( self::never() )->method( 'confirmLate' );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertSame( $this->application, $this->service->convert( self::APPLICATION, null ) );
	}

	public function test_convert_cancelled_is_not_resurrected(): void {
		$this->registrations->expects( self::never() )->method( 'confirmHeld' );
		$this->registrations->expects( self::never() )->method( 'confirmLate' );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );

		foreach ( array( 'cancelled', 'missed', 'paid_needs_resolution' ) as $state ) {
			$this->application = $this->examGuestApplication( array( 'state' => $state, 'is_held' => '0', 'active_slot' => null ) );

			self::assertSame( $this->application, $this->service->convert( self::APPLICATION, null ), $state );
		}

		self::assertSame( array(), $this->updates );
	}

	public function test_convert_unknown_application_is_refused(): void {
		$applications = $this->createMock( ExamGuestApplicationRepository::class );
		$applications->method( 'findForUpdate' )->willReturn( null );
		$this->applications = $applications;

		$this->assertRefusal( ErrorCode::ExamConflict, fn () => $this->makeService()->convert( 404, null ), 'Заявка не найдена.' );
	}

	// ---- освобождение --------------------------------------------------------------------------------------------------------------

	public function test_release_expired_frees_seat_once(): void {
		$this->applications->method( 'listExpiredHeldIds' )->with( '2026-03-10 07:00:00', 100 )->willReturn( array( 9 ) );
		$this->application = $this->examGuestApplication( array( 'hold_expires_at' => '2026-03-10 06:59:00' ) );
		$this->applications->method( 'releaseHeldFlag' )->willReturnOnConsecutiveCalls( true, false );
		$this->sessions->expects( self::once() )->method( 'releaseSeat' )->with( self::SESSION );

		self::assertSame( 1, $this->service->releaseExpired() );
		self::assertSame( 0, $this->service->releaseExpired(), 'Второй вызов: флаг уже снят — место не освобождается.' );
	}

	public function test_release_expired_rechecks_expiry_under_lock(): void {
		$this->applications->method( 'listExpiredHeldIds' )->willReturn( array( 9 ) );
		$this->application = $this->examGuestApplication( array( 'hold_expires_at' => '2026-03-10 07:30:00' ) );
		$this->applications->expects( self::never() )->method( 'releaseHeldFlag' );

		self::assertSame( 0, $this->service->releaseExpired() );
	}

	public function test_release_expired_skips_application_confirmed_while_tick_waited(): void {
		$this->applications->method( 'listExpiredHeldIds' )->willReturn( array( 9 ) );
		$this->application = $this->examGuestApplication( array( 'is_held' => '0', 'state' => 'confirmed', 'hold_expires_at' => '2026-03-10 06:59:00' ) );
		$this->applications->expects( self::never() )->method( 'releaseHeldFlag' );

		self::assertSame( 0, $this->service->releaseExpired() );
	}

	public function test_release_keeps_application_row_and_marks_state(): void {
		$this->applications->method( 'releaseHeldFlag' )->willReturn( true );

		self::assertTrue( $this->service->release( self::APPLICATION, GuestApplicationState::Cancelled ) );

		self::assertCount( 1, $this->updates );
		self::assertSame( array( 'state' => 'cancelled', 'active_slot' => null ), $this->updates[0]['data'], 'Строка заявки остаётся: поздняя оплата должна её найти.' );
		self::assertFalse( ( new \ReflectionClass( ExamGuestApplicationRepository::class ) )->hasMethod( 'delete' ), 'Удаления заявок в репозитории нет.' );
	}

	public function test_release_of_already_released_hold_does_nothing(): void {
		$this->applications->method( 'releaseHeldFlag' )->willReturn( false );
		$this->sessions->expects( self::never() )->method( 'releaseSeat' );
		$this->applications->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->release( self::APPLICATION, GuestApplicationState::ExpiredUnpaid ) );
	}

	public function test_release_rejects_state_that_holds_a_seat(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->service->release( self::APPLICATION, GuestApplicationState::Hold );
	}

	public function test_release_expired_logs_error_and_continues_with_next(): void {
		$this->applications->method( 'listExpiredHeldIds' )->willReturn( array( 9, 10 ) );
		$this->application = $this->examGuestApplication( array( 'hold_expires_at' => '2026-03-10 06:59:00' ) );
		$this->applications->method( 'releaseHeldFlag' )->willReturnOnConsecutiveCalls( true, true );
		$this->sessions->method( 'releaseSeat' )->willReturnOnConsecutiveCalls( $this->throwException( new \RuntimeException( 'сбой базы' ) ), true );

		self::assertSame( 1, $this->service->releaseExpired(), 'Сбой первой заявки не останавливает вторую.' );
	}

	// ---- ручное урегулирование оплаченной заявки (8.8.4) ----------------------------------------------------------------------------

	private function needsHelp( array $override = array() ): void {
		$this->application = $this->examGuestApplication( array_merge( array( 'state' => 'paid_needs_resolution', 'is_held' => '0', 'participant_id' => '31' ), $override ) );
	}

	public function test_resolve_transfer_occupies_seat_and_confirms(): void {
		$this->needsHelp();
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'id' => '8', 'event_id' => '3' ) ) );
		$this->applications->method( 'hasActiveByIdentity' )->willReturn( false );
		$this->registrations->expects( self::once() )->method( 'confirmByStaff' )->with( 31, 8, 'app-' . self::APPLICATION, self::SOURCE, 77 )->willReturn( $this->registrationResult() );
		$inserted = null;
		$this->resolutionsRepo->expects( self::once() )->method( 'insert' )->willReturnCallback( function ( array $row ) use ( &$inserted ): int {
			$inserted = $row;
			return 1;
		} );

		$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::Transferred, 8, 'Гость оплатил, сеанс переполнен', null );

		$final = $this->updates[ array_key_last( $this->updates ) ];
		self::assertSame( 'confirmed', $final['data']['state'] );
		self::assertSame( 55, $final['data']['registration_id'] );
		self::assertSame( 'transferred', $inserted['kind'] );
		self::assertSame( self::SESSION, $inserted['old_session_id'] );
		self::assertSame( 8, $inserted['new_session_id'] );
		self::assertSame( 77, $inserted['actor_user_id'] );
	}

	public function test_resolve_refunded_outside_closes_without_seat_and_stores_amount(): void {
		$this->needsHelp();
		$this->registrations->expects( self::never() )->method( 'confirmByStaff' );
		$this->sessions->expects( self::never() )->method( 'occupySeat' );
		$inserted = null;
		$this->resolutionsRepo->method( 'insert' )->willReturnCallback( function ( array $row ) use ( &$inserted ): int {
			$inserted = $row;
			return 1;
		} );

		$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::RefundedOutside, null, 'Вернули в ЮKassa', '1500.00' );

		$final = $this->updates[ array_key_last( $this->updates ) ];
		self::assertSame( 'cancelled', $final['data']['state'] );
		self::assertNull( $final['data']['active_slot'] );
		self::assertSame( 'refunded_outside', $inserted['kind'] );
		self::assertSame( '1500.00', $inserted['amount'] );
	}

	public function test_resolve_other_is_only_a_note(): void {
		$this->needsHelp();
		$this->registrations->expects( self::never() )->method( 'confirmByStaff' );
		$inserted = null;
		$this->resolutionsRepo->method( 'insert' )->willReturnCallback( function ( array $row ) use ( &$inserted ): int {
			$inserted = $row;
			return 1;
		} );

		$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::Other, null, 'Договорились лично', null );

		self::assertSame( 'other', $inserted['kind'] );
		self::assertArrayNotHasKey( 'amount', $inserted );
	}

	public function test_resolve_refused_for_already_resolved_application(): void {
		$this->application = $this->examGuestApplication( array( 'state' => 'confirmed' ) );
		$this->resolutionsRepo->expects( self::never() )->method( 'insert' );

		$this->expectException( CodedException::class );
		$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::Other, null, 'Повтор', null );
	}

	public function test_resolve_transfer_refused_for_session_of_another_event(): void {
		$this->needsHelp();
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'id' => '8', 'event_id' => '99' ) ) );
		$this->registrations->expects( self::never() )->method( 'confirmByStaff' );

		$this->expectException( CodedException::class );
		$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::Transferred, 8, 'Другое проведение', null );
	}

	public function test_resolve_transfer_refused_when_guest_has_another_active_application(): void {
		$this->needsHelp();
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'id' => '8', 'event_id' => '3' ) ) );
		$this->applications->method( 'hasActiveByIdentity' )->willReturn( true );
		$this->registrations->expects( self::never() )->method( 'confirmByStaff' );

		$this->expectException( CodedException::class );
		$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::Transferred, 8, 'Две заявки', null );
	}

	public function test_failed_transfer_rolls_back_and_writes_no_resolution(): void {
		$this->needsHelp();
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'id' => '8', 'event_id' => '3' ) ) );
		$this->applications->method( 'hasActiveByIdentity' )->willReturn( false );
		$this->registrations->method( 'confirmByStaff' )->willThrowException( new CodedException( \Inc\Enums\Log\ErrorCode::ExamFull, 'Свободных мест нет.' ) );
		$this->resolutionsRepo->expects( self::never() )->method( 'insert' );

		try {
			$this->service->resolveManually( 77, self::APPLICATION, \Inc\Enums\Exam\ManualResolutionKind::Transferred, 8, 'Нет мест', null );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertContains( 'ROLLBACK', $this->db->log );
		}
	}
}
