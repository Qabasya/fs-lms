<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Course\RoomDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamEventService;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamLaunchChecklist;
use Inc\Services\Exam\ExamRegistrationService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamRoomService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\ExamVariantPolicy;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Проведения и сеансы: проверки дат, кабинета и варианта, публикация со снимком, отмена, синхронизация вместимости.
 *
 * Сайт — Москва (+3), «сейчас» — 2026-03-01 10:00 местного (07:00 UTC). Сеанс 2026-03-10 10:00 местного = 07:00 UTC.
 * Кабинет проверяется настоящим `ExamRoomService` над мок-репозиториями, поэтому тесты кабинета не пусты.
 *
 * Состояние репозиториев задаётся свойствами сценария (`$event`, `$session`, `$room`, `$sessionList`, `$sessionBusy`, `$lessonBusy`),
 * заглушки читают их при вызове. Журнал `$db->log` фиксирует границы транзакции и порядок обращений.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamEventServiceTest extends TestCase {

	use ExamFixtures;

	private const ACTOR      = 10;
	private const EVENT      = 3;
	private const SESSION    = 7;
	private const ASSESSMENT = 500;

	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamRegistrationRepository&MockObject $registrations;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ExamAccessGuard&MockObject $guard;
	private ExamVariantPolicy&MockObject $variants;
	private RoomRepository&MockObject $rooms;
	private AssessmentManager&MockObject $assessments;
	private ExamOutbox&MockObject $outbox;
	private ExamRegistrationService&MockObject $registrationService;
	private ExamHoldService&MockObject $holds;
	private ExamGuestApplicationRepository&MockObject $guestApplications;
	private ExamLaunchChecklist&MockObject $checklist;
	private ExamEventService $service;
	private object $db;
	private \wpdb $originalWpdb;

	// Сценарий теста.
	private ExamEventDTO $event;
	private ExamSessionDTO $session;
	private RoomDTO $room;
	/** @var ExamSessionDTO[] */
	private array $sessionList = array();
	private bool $sessionBusy  = false;
	private bool $lessonBusy   = false;
	/** @var array<string, mixed> */
	private array $eventInserted = array();
	/** @var array<string, mixed> */
	private array $sessionInserted = array();

	/** @var array{utc: string, local: string} */
	private array $now = array( 'utc' => '2026-03-01 07:00:00', 'local' => '2026-03-01 10:00:00' );

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';

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

		$this->event   = $this->examEvent();
		$this->session = $this->examSession();
		$this->room    = $this->room();

		$this->events        = $this->createMock( ExamEventRepository::class );
		$this->sessions      = $this->createMock( ExamSessionRepository::class );
		$this->registrations = $this->createMock( ExamRegistrationRepository::class );
		$this->attempts      = $this->createMock( AssessmentAttemptRepository::class );
		$this->guard         = $this->createMock( ExamAccessGuard::class );
		$this->variants      = $this->createMock( ExamVariantPolicy::class );
		$this->rooms         = $this->createMock( RoomRepository::class );
		$this->assessments   = $this->createMock( AssessmentManager::class );
		$this->outbox        = $this->createMock( ExamOutbox::class );

		$this->registrationService = $this->createMock( ExamRegistrationService::class );
		$this->holds               = $this->createMock( ExamHoldService::class );
		$this->guestApplications   = $this->createMock( ExamGuestApplicationRepository::class );
		$this->checklist           = $this->createMock( ExamLaunchChecklist::class );
		$this->checklist->method( 'isReady' )->willReturn( true );

		$this->guard->method( 'canManageSubject' )->willReturn( true );
		$this->guard->method( 'canManageEvent' )->willReturn( true );

		$this->events->method( 'find' )->willReturnCallback( fn (): ExamEventDTO => $this->event );
		$this->events->method( 'findForUpdate' )->willReturnCallback( function (): ExamEventDTO {
			$this->db->log[] = 'event-lock';
			return $this->event;
		} );
		$this->events->method( 'update' )->willReturn( true );
		$this->events->method( 'insert' )->willReturnCallback( function ( array $data ): int {
			$this->eventInserted = $data;
			return self::EVENT;
		} );

		$this->sessions->method( 'find' )->willReturnCallback( fn (): ExamSessionDTO => $this->session );
		$this->sessions->method( 'findForUpdate' )->willReturnCallback( fn (): ExamSessionDTO => $this->session );
		$this->sessions->method( 'findByEvent' )->willReturnCallback( fn (): array => $this->sessionList );
		$this->sessions->method( 'insert' )->willReturnCallback( function ( array $data ): int {
			$this->sessionInserted = $data;
			return self::SESSION;
		} );
		$this->sessions->method( 'update' )->willReturn( true );
		$this->sessions->method( 'isRoomBusy' )->willReturnCallback( function (): bool {
			$this->db->log[] = 'busy-check';
			return $this->sessionBusy;
		} );

		$this->rooms->method( 'find' )->willReturnCallback( fn (): RoomDTO => $this->room );
		$this->rooms->method( 'lockForUpdate' )->willReturnCallback( function (): void {
			$this->db->log[] = 'room-lock';
		} );
		$this->rooms->method( 'isBusy' )->willReturnCallback( function (): bool {
			$this->db->log[] = 'lesson-check';
			return $this->lessonBusy;
		} );

		$this->assessments->method( 'get' )->willReturnCallback( fn ( int $id ): AssessmentDTO => $this->assessment( $id ) );

		$this->service = $this->makeService();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->originalWpdb;
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	private function makeService( ?ExamAccessGuard $guard = null ): ExamEventService {
		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( fn ( string $type = 'mysql', bool $gmt = false ): string => $gmt ? $this->now['utc'] : $this->now['local'] );
		$time = new ExamTime( $clock );

		$formats = $this->createMock( ExamFormatRegistry::class );
		$formats->method( 'for' )->willReturn( new ExamFormatDTO(
			kind: AssessmentKind::EgeComputer, direction: ExamDirection::Ege, unitCount: 27, primaryMax: 29, secondaryMax: 100,
			gradeMax: 0, durationMinutes: 235, scale: array( 0 => 0, 1 => 7 ), unitMaxScores: array(),
		) );

		return new ExamEventService(
			$this->events, $this->sessions, $this->registrations, $this->attempts, $guard ?? $this->guard, $this->variants,
			new ExamRoomService( $this->rooms, $this->sessions, $time, new \Inc\Services\Course\RoomAvailabilityService( $this->rooms, $this->sessions, $time ) ), $formats, $this->assessments, $this->outbox, $time,
			$this->registrationService, $this->holds, $this->guestApplications, $this->checklist,
		);
	}

	/** @param array<string, mixed> $override */
	private function room( array $override = array() ): RoomDTO {
		return RoomDTO::fromArray( array_merge( array( 'id' => '2', 'name' => '305', 'seats' => '12', 'allowed_subjects' => '[]', 'is_active' => '1' ), $override ) );
	}

	private function assessment( int $id ): AssessmentDTO {
		return new AssessmentDTO(
			id: $id, subjectKey: 'inf_ege', title: 'Вариант ' . $id, taskIds: array( 11, 12 ), timeLimit: 235, attemptsAllowed: 1,
			passScore: 0.0, scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: AssessmentKind::EgeComputer,
			taskPoints: array( 11 => 1.0, 12 => 2.0 ), scoreMap: array(), taskNumbers: array( 11 => '1', 12 => '2' ),
		);
	}

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function eventInput( array $override = array() ): array {
		return array_merge( array(
			'subject_key' => 'inf_ege', 'title' => 'Пробный ЕГЭ', 'description' => '', 'period_from' => '2026-03-10', 'period_to' => '2026-03-12',
			'registration_opens_at' => '2026-03-02 09:00', 'registration_closes_at' => '2026-03-09 18:00', 'default_assessment_id' => 0,
			'guest_registration_enabled' => '0',
		), $override );
	}

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function sessionInput( array $override = array() ): array {
		return array_merge( array( 'date' => '2026-03-10', 'time' => '10:00', 'assessment_id' => self::ASSESSMENT, 'room_id' => 2 ), $override );
	}

	private function assertRefusal( ErrorCode $code, string $message, callable $call ): void {
		try {
			$call();
			self::fail( 'Ожидался отказ: ' . $message );
		} catch ( CodedException $e ) {
			self::assertSame( $message, $e->getMessage() );
			self::assertSame( $code, $e->errorCode );
		}
	}

	private const STALE = 'Данные изменились: обновите страницу и повторите.';

	// ---- создание проведения -------------------------------------------------------------------------------------------------------

	public function test_create_draft_writes_utc_times_owner_and_draft_status(): void {
		$this->service->createDraft( self::ACTOR, $this->eventInput() );

		$stored = $this->eventInserted;
		self::assertSame( 'draft', $stored['status'] );
		self::assertSame( self::ACTOR, $stored['owner_user_id'] );
		self::assertSame( 'inf_ege', $stored['subject_key'] );
		self::assertSame( '2026-03-02 06:00:00', $stored['registration_opens_at'], '09:00 МСК = 06:00 UTC.' );
		self::assertSame( '2026-03-09 15:00:00', $stored['registration_closes_at'] );
		self::assertNull( $stored['description'] );
		self::assertSame( 1, $stored['version'] );
	}

	public function test_create_draft_requires_subject_scope(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageSubject' )->with( self::ACTOR, 'math' )->willReturn( false );
		$this->events->expects( self::never() )->method( 'insert' );
		$service = $this->makeService( $guard );

		$this->assertRefusal( ErrorCode::ExamAccess, 'Нет доступа к этому проведению.', fn () => $service->createDraft( self::ACTOR, $this->eventInput( array( 'subject_key' => 'math' ) ) ) );
	}

	public function test_create_rejects_empty_title(): void {
		$this->assertRefusal( ErrorCode::ExamConflict, 'Укажите название проведения.', fn () => $this->service->createDraft( self::ACTOR, $this->eventInput( array( 'title' => '  ' ) ) ) );
	}

	public function test_create_rejects_reversed_period(): void {
		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Дата начала позже даты окончания.',
			fn () => $this->service->createDraft( self::ACTOR, $this->eventInput( array( 'period_from' => '2026-03-12', 'period_to' => '2026-03-10' ) ) )
		);
	}

	public function test_create_rejects_registration_opening_after_closing(): void {
		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Открытие записи позже её закрытия.',
			fn () => $this->service->createDraft( self::ACTOR, $this->eventInput( array( 'registration_opens_at' => '2026-03-09 19:00', 'registration_closes_at' => '2026-03-09 18:00' ) ) )
		);
	}

	public function test_create_rejects_registration_closing_after_period(): void {
		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Запись не может закрываться позже последнего дня проведения.',
			fn () => $this->service->createDraft( self::ACTOR, $this->eventInput( array( 'registration_closes_at' => '2026-03-13 00:30' ) ) )
		);
	}

	public function test_registration_may_close_at_the_last_minute_of_the_last_day(): void {
		$this->service->createDraft( self::ACTOR, $this->eventInput( array( 'registration_closes_at' => '2026-03-12 23:59' ) ) );

		self::assertSame( '2026-03-12 20:59:00', $this->eventInserted['registration_closes_at'] );
	}

	public function test_create_rejects_variant_of_other_subject(): void {
		$this->variants->method( 'assert' )->willThrowException( new CodedException( ErrorCode::ExamConflict, 'Вариант относится к другому предмету.' ) );
		$this->events->expects( self::never() )->method( 'insert' );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Вариант относится к другому предмету.',
			fn () => $this->service->createDraft( self::ACTOR, $this->eventInput( array( 'default_assessment_id' => 77 ) ) )
		);
	}

	public function test_period_may_span_weekend_and_month_boundary(): void {
		$this->service->createDraft( self::ACTOR, $this->eventInput( array(
			'period_from' => '2026-10-30', 'period_to' => '2026-11-08', 'registration_opens_at' => '2026-10-01 09:00', 'registration_closes_at' => '2026-11-06 18:00',
		) ) );

		self::assertSame( '2026-10-30', $this->eventInserted['period_from'] );
		self::assertSame( '2026-11-08', $this->eventInserted['period_to'] );
	}

	public function test_impossible_calendar_date_is_rejected(): void {
		$this->assertRefusal( ErrorCode::ExamConflict, 'Укажите даты проведения.', fn () => $this->service->createDraft( self::ACTOR, $this->eventInput( array( 'period_from' => '2026-02-30' ) ) ) );
	}

	// ---- правка проведения ---------------------------------------------------------------------------------------------------------

	public function test_update_with_stale_version_fails(): void {
		$this->events->expects( self::never() )->method( 'update' );

		$this->assertRefusal( ErrorCode::ExamStale, self::STALE, fn () => $this->service->updateEvent( self::ACTOR, self::EVENT, $this->eventInput(), 5 ) );
	}

	public function test_update_is_refused_for_cancelled_event(): void {
		$this->event = $this->examEvent( array( 'status' => 'cancelled' ) );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Проведение завершено или отменено: изменить его нельзя.', fn () => $this->service->updateEvent( self::ACTOR, self::EVENT, $this->eventInput(), 1 ) );
	}

	public function test_update_refuses_period_that_leaves_a_session_outside(): void {
		$this->sessionList = array( $this->examSession( array( 'scheduled_at' => '2026-03-12 07:00:00', 'planned_end_at' => '2026-03-12 10:55:00' ) ) );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Есть сеансы вне нового периода проведения.',
			fn () => $this->service->updateEvent( self::ACTOR, self::EVENT, $this->eventInput( array( 'period_to' => '2026-03-11' ) ), 1 )
		);
	}

	public function test_update_ignores_cancelled_session_when_checking_period(): void {
		$this->sessionList = array( $this->examSession( array( 'scheduled_at' => '2026-03-12 07:00:00', 'status' => 'cancelled' ) ) );

		$this->service->updateEvent( self::ACTOR, self::EVENT, $this->eventInput( array( 'period_to' => '2026-03-11' ) ), 1 );
		$this->addToAssertionCount( 1 );
	}

	public function test_update_saves_with_expected_version(): void {
		$this->events->expects( self::once() )->method( 'update' )
			->with( self::EVENT, self::callback( static fn ( array $d ): bool => 'Новое имя' === $d['title'] ), 1 );

		$this->service->updateEvent( self::ACTOR, self::EVENT, $this->eventInput( array( 'title' => 'Новое имя' ) ), 1 );
	}

	// ---- права ---------------------------------------------------------------------------------------------------------------------

	public function test_teacher_cannot_manage_foreign_event(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$service = $this->makeService( $guard );

		$this->events->expects( self::never() )->method( 'update' );
		$this->sessions->expects( self::never() )->method( 'insert' );
		$denied = 'Нет доступа к этому проведению.';

		$this->assertRefusal( ErrorCode::ExamAccess, $denied, fn () => $service->publish( 99, self::EVENT, 1 ) );
		$this->assertRefusal( ErrorCode::ExamAccess, $denied, fn () => $service->cancelEvent( 99, self::EVENT, 'Причина', 1 ) );
		$this->assertRefusal( ErrorCode::ExamAccess, $denied, fn () => $service->saveSession( 99, self::EVENT, $this->sessionInput(), null, null ) );
		$this->assertRefusal( ErrorCode::ExamAccess, $denied, fn () => $service->updateEvent( 99, self::EVENT, $this->eventInput(), 1 ) );
		$this->assertRefusal( ErrorCode::ExamAccess, $denied, fn () => $service->deleteSession( 99, self::SESSION ) );
		$this->assertRefusal( ErrorCode::ExamAccess, $denied, fn () => $service->rebuildSnapshot( 99, self::EVENT, self::ASSESSMENT ) );
	}

	public function test_admin_manages_any_event(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->expects( self::atLeastOnce() )->method( 'canManageEvent' )->with( 3, self::isInstanceOf( ExamEventDTO::class ) )->willReturn( true );
		$service = $this->makeService( $guard );
		$this->sessionList = array( $this->session );

		$service->publish( 3, self::EVENT, 1 );

		self::assertContains( 'COMMIT', $this->db->log );
	}

	// ---- сеансы --------------------------------------------------------------------------------------------------------------------

	public function test_session_end_is_start_plus_format_duration(): void {
		$this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null );

		self::assertSame( '2026-03-10 07:00:00', $this->sessionInserted['scheduled_at'], '10:00 МСК = 07:00 UTC.' );
		self::assertSame( '2026-03-10 10:55:00', $this->sessionInserted['planned_end_at'], '10:00 + 235 минут = 13:55 МСК = 10:55 UTC.' );
	}

	public function test_session_capacity_copied_from_room_seats_and_responsible_is_event_owner(): void {
		$this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null );

		self::assertSame( 12, $this->sessionInserted['capacity'] );
		self::assertSame( 10, $this->sessionInserted['responsible_user_id'] );
		self::assertSame( 'open', $this->sessionInserted['status'] );
		self::assertSame( 0, $this->sessionInserted['occupied_count'] );
		self::assertSame( self::EVENT, $this->sessionInserted['event_id'] );
	}

	public function test_session_rejects_room_without_capacity(): void {
		$this->room = $this->room( array( 'seats' => '0' ) );
		$this->sessions->expects( self::never() )->method( 'insert' );

		$this->assertRefusal(
			ErrorCode::ExamRoom,
			'Укажите вместимость кабинета в „Настройки → Кабинеты“.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null )
		);
	}

	public function test_session_rejects_room_not_allowing_subject(): void {
		$this->room = $this->room( array( 'allowed_subjects' => '["python"]' ) );

		$this->assertRefusal(
			ErrorCode::ExamRoom,
			'Кабинет не предназначен для этого предмета.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null )
		);
	}

	public function test_session_rejects_date_outside_period(): void {
		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Дата сеанса вне периода проведения.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'date' => '2026-03-13' ) ), null, null )
		);
	}

	public function test_session_rejects_busy_room(): void {
		$this->sessionBusy = true;
		$this->sessions->expects( self::never() )->method( 'insert' );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Кабинет занят в это время.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null ) );
		self::assertContains( 'ROLLBACK', $this->db->log );
	}

	public function test_session_rejects_room_busy_with_lesson(): void {
		$this->lessonBusy = true;

		$this->assertRefusal( ErrorCode::ExamConflict, 'Кабинет занят в это время.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null ) );
	}

	public function test_session_rejects_wrong_time_format(): void {
		$this->assertRefusal( ErrorCode::ExamConflict, 'Укажите время сеанса.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'time' => '25:00' ) ), null, null ) );
	}

	public function test_session_requires_room(): void {
		$this->assertRefusal( ErrorCode::ExamRoom, 'Выберите кабинет.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'room_id' => 0 ) ), null, null ) );
	}

	public function test_save_session_locks_room_before_check(): void {
		$this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null );

		$log = $this->db->log;
		self::assertSame( array( 'START TRANSACTION', 'event-lock', 'room-lock' ), array_slice( $log, 0, 3 ), 'Сначала проведение, затем кабинет.' );
		$lock = (int) array_search( 'room-lock', $log, true );
		self::assertLessThan( (int) array_search( 'busy-check', $log, true ), $lock, 'Кабинет заблокирован до проверки сеансов.' );
		self::assertLessThan( (int) array_search( 'lesson-check', $log, true ), $lock, 'Кабинет заблокирован до проверки занятий.' );
		self::assertSame( 'COMMIT', end( $log ) );
	}

	public function test_locked_session_cannot_change_variant(): void {
		$this->session = $this->examSession( array( 'first_started_at' => '2026-03-10 07:05:00', 'occupied_count' => '3' ) );

		$this->assertRefusal(
			ErrorCode::ExamStarted,
			'Сеанс уже начат: изменить можно только индивидуально.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'assessment_id' => 501 ) ), self::SESSION, 1 )
		);
	}

	public function test_session_with_registrations_cannot_be_moved_here(): void {
		$this->session = $this->examSession( array( 'occupied_count' => '3' ) );
		$this->sessions->expects( self::never() )->method( 'update' );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'В сеансе есть участники: используйте перенос с причиной.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'time' => '12:00' ) ), self::SESSION, 1 )
		);
	}

	public function test_capacity_cannot_drop_below_occupied(): void {
		$this->session = $this->examSession( array( 'occupied_count' => '10' ) );
		$this->room    = $this->room( array( 'seats' => '8' ) );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'В сеансе уже занято 10 мест, в кабинете их меньше.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), self::SESSION, 1 )
		);
	}

	public function test_session_edit_with_stale_version_fails(): void {
		$this->session = $this->examSession( array( 'version' => '4' ) );

		$this->assertRefusal( ErrorCode::ExamStale, self::STALE, fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), self::SESSION, 1 ) );
	}

	public function test_session_edit_requires_expected_version(): void {
		$this->assertRefusal( ErrorCode::ExamStale, self::STALE, fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), self::SESSION, null ) );
	}

	public function test_session_of_another_event_cannot_be_edited_through_this_one(): void {
		$this->session = $this->examSession( array( 'event_id' => '99' ) );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Сеанс не найден.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), self::SESSION, 1 ) );
	}

	public function test_cancelled_session_cannot_be_edited(): void {
		$this->session = $this->examSession( array( 'status' => 'cancelled' ) );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Сеанс отменён: изменить его нельзя.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), self::SESSION, 1 ) );
	}

	public function test_empty_session_is_edited_in_place_excluding_itself_from_room_check(): void {
		$this->sessions->expects( self::once() )->method( 'isRoomBusy' )->with( 2, '2026-03-10 09:00:00', '2026-03-10 12:55:00', self::SESSION );
		$this->sessions->expects( self::once() )->method( 'update' )->with( self::SESSION, self::callback( static fn ( array $d ): bool => 12 === $d['capacity'] && '2026-03-10 09:00:00' === $d['scheduled_at'] ), 1 );

		$this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'time' => '12:00' ) ), self::SESSION, 1 );
	}

	public function test_failed_versioned_session_update_is_reported_as_stale(): void {
		$sessions = $this->createMock( ExamSessionRepository::class );
		$sessions->method( 'findForUpdate' )->willReturn( $this->session );
		$sessions->method( 'find' )->willReturn( $this->session );
		$sessions->method( 'update' )->willReturn( false );
		$this->sessions = $sessions;
		$service        = $this->makeService();

		$this->assertRefusal( ErrorCode::ExamStale, self::STALE, fn () => $service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), self::SESSION, 1 ) );
		self::assertContains( 'ROLLBACK', $this->db->log );
	}

	public function test_new_variant_in_published_event_is_added_to_snapshot(): void {
		$this->event = $this->examEvent( array( 'status' => 'published', 'version' => '4', 'variant_snapshot' => (string) json_encode( array( '501' => array( 'duration_minutes' => 235 ) ) ) ) );
		$this->events->expects( self::once() )->method( 'update' )->with(
			self::EVENT,
			self::callback( static function ( array $d ): bool {
				$snapshot = json_decode( $d['variant_snapshot'], true );
				return isset( $snapshot['501'], $snapshot['500'] ) && array( 11, 12 ) === $snapshot['500']['task_ids'];
			} ),
			4
		);

		$this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null );
	}

	public function test_known_variant_in_published_event_keeps_snapshot_untouched(): void {
		$this->event = $this->examEvent( array( 'status' => 'published', 'variant_snapshot' => (string) json_encode( array( '500' => array( 'duration_minutes' => 235 ) ) ) ) );
		$this->events->expects( self::never() )->method( 'update' );

		$this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null );
	}

	public function test_sessions_cannot_be_added_to_cancelled_event(): void {
		$this->event = $this->examEvent( array( 'status' => 'cancelled' ) );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Проведение завершено или отменено: изменить его нельзя.', fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput(), null, null ) );
	}

	public function test_delete_session_with_registrations_is_refused(): void {
		$this->registrations->method( 'listBySession' )->willReturn( array( new \stdClass() ) );
		$this->sessions->expects( self::never() )->method( 'delete' );

		$this->assertRefusal( ErrorCode::ExamConflict, 'В сеансе есть записи: используйте отмену сеанса.', fn () => $this->service->deleteSession( self::ACTOR, self::SESSION ) );
	}

	public function test_delete_session_with_occupied_seats_is_refused_even_without_registration_rows(): void {
		$this->session = $this->examSession( array( 'occupied_count' => '1' ) );
		$this->sessions->expects( self::never() )->method( 'delete' );

		$this->assertRefusal( ErrorCode::ExamConflict, 'В сеансе есть записи: используйте отмену сеанса.', fn () => $this->service->deleteSession( self::ACTOR, self::SESSION ) );
	}

	public function test_delete_empty_session(): void {
		$this->sessions->expects( self::once() )->method( 'delete' )->with( self::SESSION )->willReturn( true );

		$this->service->deleteSession( self::ACTOR, self::SESSION );

		self::assertSame( array( 'START TRANSACTION', 'event-lock', 'COMMIT' ), $this->db->log );
	}

	// ---- публикация ----------------------------------------------------------------------------------------------------------------

	public function test_publish_requires_future_session(): void {
		$this->sessionList = array( $this->examSession( array( 'scheduled_at' => '2026-02-20 07:00:00', 'planned_end_at' => '2026-02-20 10:55:00' ) ) );
		$this->events->expects( self::never() )->method( 'update' );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Добавьте хотя бы один сеанс в будущем.', fn () => $this->service->publish( self::ACTOR, self::EVENT, 1 ) );
	}

	public function test_publish_ignores_cancelled_future_session(): void {
		$this->sessionList = array( $this->examSession( array( 'status' => 'cancelled' ) ) );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Добавьте хотя бы один сеанс в будущем.', fn () => $this->service->publish( self::ACTOR, self::EVENT, 1 ) );
	}

	public function test_publish_requires_registration_window(): void {
		$this->event       = $this->examEvent( array( 'registration_opens_at' => null ) );
		$this->sessionList = array( $this->session );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Задайте время открытия и закрытия записи.', fn () => $this->service->publish( self::ACTOR, self::EVENT, 1 ) );
	}

	public function test_publish_builds_snapshot_per_variant(): void {
		$this->sessionList = array(
			$this->examSession( array( 'id' => '7', 'assessment_id' => '500' ) ),
			$this->examSession( array( 'id' => '8', 'assessment_id' => '501' ) ),
			$this->examSession( array( 'id' => '9', 'assessment_id' => '500', 'scheduled_at' => '2026-03-11 07:00:00', 'planned_end_at' => '2026-03-11 10:55:00' ) ),
			$this->examSession( array( 'id' => '10', 'assessment_id' => '502', 'status' => 'cancelled' ) ),
		);
		$this->variants->expects( self::exactly( 2 ) )->method( 'assert' );
		$this->events->expects( self::once() )->method( 'update' )->with(
			self::EVENT,
			self::callback( static function ( array $d ): bool {
				$snapshot = json_decode( $d['variant_snapshot'], true );
				return array( '500', '501' ) === array_map( 'strval', array_keys( $snapshot ) )
					&& 'published' === $d['status'] && '2026-03-01 07:00:00' === $d['published_at']
					&& 235 === $snapshot['500']['duration_minutes'] && array( 11, 12 ) === $snapshot['500']['task_ids']
					&& 29 === $snapshot['500']['primary_max'] && 100 === $snapshot['500']['secondary_max']
					&& array( '11' => '1', '12' => '2' ) === array_map( 'strval', $snapshot['500']['task_numbers'] )
					&& 'ege_computer' === $snapshot['501']['kind'] && '2026-03-01 07:00:00' === $snapshot['501']['built_at'];
			} ),
			1
		);

		$this->service->publish( self::ACTOR, self::EVENT, 1 );
	}

	public function test_publish_writes_outbox_event_in_transaction(): void {
		$this->sessionList = array( $this->session );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::EventPublished, 'event', self::EVENT, 2, array( 'event_id' => self::EVENT ) )
			->willReturnCallback( function (): void {
				$this->db->log[] = 'outbox';
			} );

		$this->service->publish( self::ACTOR, self::EVENT, 1 );

		self::assertSame( array( 'START TRANSACTION', 'event-lock', 'outbox', 'COMMIT' ), $this->db->log );
	}

	public function test_failed_outbox_write_rolls_publish_back(): void {
		$this->sessionList = array( $this->session );
		$this->outbox->method( 'add' )->willThrowException( new \RuntimeException( 'outbox недоступен' ) );

		try {
			$this->service->publish( self::ACTOR, self::EVENT, 1 );
			self::fail( 'Ожидалось исключение.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'outbox недоступен', $e->getMessage() );
			self::assertContains( 'ROLLBACK', $this->db->log );
			self::assertNotContains( 'COMMIT', $this->db->log );
		}
	}

	public function test_publish_with_stale_version_fails(): void {
		$this->events->expects( self::never() )->method( 'update' );
		$this->outbox->expects( self::never() )->method( 'add' );

		$this->assertRefusal( ErrorCode::ExamStale, self::STALE, fn () => $this->service->publish( self::ACTOR, self::EVENT, 9 ) );
	}

	public function test_publish_of_published_event_is_refused(): void {
		$this->event = $this->examEvent( array( 'status' => 'published' ) );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Опубликовать можно только черновик.', fn () => $this->service->publish( self::ACTOR, self::EVENT, 1 ) );
	}

	// ---- отмена, снимок, вместимость -----------------------------------------------------------------------------------------------

	public function test_cancel_requires_reason(): void {
		$this->events->expects( self::never() )->method( 'update' );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Укажите причину отмены.', fn () => $this->service->cancelEvent( self::ACTOR, self::EVENT, '   ', 1 ) );
	}

	public function test_cancel_closes_open_sessions_and_writes_outbox(): void {
		$this->sessions->expects( self::once() )->method( 'cancelOpenByEvent' )->with( self::EVENT, 'Нет места', '2026-03-01 07:00:00' )->willReturn( 2 );
		$this->events->expects( self::once() )->method( 'update' )->with(
			self::EVENT,
			array( 'status' => 'cancelled', 'cancel_reason' => 'Нет места', 'cancelled_at' => '2026-03-01 07:00:00' ),
			1
		);
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::EventCancelled, 'event', self::EVENT, 2, array( 'event_id' => self::EVENT, 'reason' => 'Нет места' ) );

		$this->service->cancelEvent( self::ACTOR, self::EVENT, '  Нет места ', 1 );
	}

	public function test_cancel_event_cancels_all_unstarted_sessions(): void {
		$other             = $this->examSession( array( 'id' => '8' ) );
		$this->sessionList = array( $this->session, $other );
		$this->sessions->method( 'cancelOpenByEvent' )->willReturn( 2 );
		$this->registrations->method( 'listBySession' )->willReturnCallback( fn ( int $sessionId ): array => 7 === $sessionId
			? array( $this->registration( 20 ), $this->registration( 21 ) )
			: array() );

		$cancelled = array();
		$this->registrationService->method( 'cancelByStaff' )->willReturnCallback( static function ( int $actor, int $registrationId, string $reason ) use ( &$cancelled ): void {
			$cancelled[] = array( $registrationId, $reason );
		} );

		$this->service->cancelEvent( self::ACTOR, self::EVENT, 'Нет места', 1 );

		self::assertSame( array( array( 20, 'Нет места' ), array( 21, 'Нет места' ) ), $cancelled );
	}

	public function test_cancel_event_denied_when_any_attempt_started(): void {
		$this->sessionList = array( $this->examSession( array( 'first_started_at' => '2026-03-10 07:05:00' ) ) );
		$this->sessions->expects( self::never() )->method( 'cancelOpenByEvent' );
		$this->events->expects( self::never() )->method( 'update' );

		$this->assertRefusal( ErrorCode::ExamStarted, 'Экзамен уже начат: отмена невозможна.', fn () => $this->service->cancelEvent( self::ACTOR, self::EVENT, 'Причина', 1 ) );
	}

	public function test_move_session_requires_reason_and_writes_outbox(): void {
		$this->assertRefusal( ErrorCode::ExamConflict, 'Укажите причину переноса.', fn () => $this->service->moveSession( self::ACTOR, self::SESSION, $this->moveInput(), '  ', 1 ) );

		$this->session = $this->examSession( array( 'occupied_count' => '3' ) );
		$this->sessions->expects( self::once() )->method( 'update' )->with(
			self::SESSION,
			array( 'scheduled_at' => '2026-03-11 09:00:00', 'planned_end_at' => '2026-03-11 12:55:00', 'room_id' => 2, 'capacity' => 12 ),
			1
		)->willReturn( true );
		$this->outbox->expects( self::once() )->method( 'add' )->with(
			ExamOutboxEvent::SessionMoved,
			'session',
			self::SESSION,
			2,
			array(
				'session_id'       => self::SESSION,
				'event_id'         => self::EVENT,
				'old_scheduled_at' => '2026-03-10 07:00:00',
				'new_scheduled_at' => '2026-03-11 09:00:00',
				'old_room_id'      => 2,
				'new_room_id'      => 2,
				'reason'           => 'Болезнь преподавателя',
			)
		);

		$this->service->moveSession( self::ACTOR, self::SESSION, $this->moveInput(), ' Болезнь преподавателя ', 1 );
	}

	public function test_move_locked_session_is_denied(): void {
		$this->session = $this->examSession( array( 'first_started_at' => '2026-03-10 07:05:00', 'occupied_count' => '3' ) );
		$this->sessions->expects( self::never() )->method( 'update' );

		$this->assertRefusal( ErrorCode::ExamStarted, 'Сеанс уже начат: перенести его нельзя.', fn () => $this->service->moveSession( self::ACTOR, self::SESSION, $this->moveInput(), 'Причина', 1 ) );
	}

	public function test_save_session_with_participants_is_denied_without_move(): void {
		$this->session = $this->examSession( array( 'occupied_count' => '3' ) );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'В сеансе есть участники: используйте перенос с причиной.',
			fn () => $this->service->saveSession( self::ACTOR, self::EVENT, $this->sessionInput( array( 'date' => '2026-03-11' ) ), self::SESSION, 1 )
		);
	}

	public function test_cancel_session_cancels_registrations_and_releases_holds(): void {
		$this->session = $this->examSession( array( 'occupied_count' => '3' ) );
		$this->sessions->expects( self::once() )->method( 'update' )->with( self::SESSION, array( 'status' => 'cancelled', 'cancel_reason' => 'Авария' ), 1 )->willReturn( true );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::SessionCancelled, 'session', self::SESSION, 2, $this->anything() );
		$this->registrations->method( 'listBySession' )->willReturn( array( $this->registration( 20 ) ) );
		$this->guestApplications->method( 'listHeldIdsBySession' )->willReturn( array( 90, 91 ) );

		$this->registrationService->expects( self::once() )->method( 'cancelByStaff' )->with( self::ACTOR, 20, 'Авария' );
		$released = array();
		$this->holds->method( 'release' )->willReturnCallback( static function ( int $id, $state ) use ( &$released ): bool {
			$released[] = array( $id, $state->value );
			return true;
		} );

		$this->service->cancelSession( self::ACTOR, self::SESSION, 'Авария', 1 );

		self::assertSame( array( array( 90, 'cancelled' ), array( 91, 'cancelled' ) ), $released );
	}

	public function test_cancel_session_with_started_attempt_is_denied(): void {
		$this->session = $this->examSession( array( 'first_started_at' => '2026-03-10 07:05:00' ) );
		$this->sessions->expects( self::never() )->method( 'update' );
		$this->registrationService->expects( self::never() )->method( 'cancelByStaff' );

		$this->assertRefusal( ErrorCode::ExamStarted, 'Сеанс уже начат.', fn () => $this->service->cancelSession( self::ACTOR, self::SESSION, 'Причина', 1 ) );
	}

	public function test_cancel_session_requires_reason(): void {
		$this->assertRefusal( ErrorCode::ExamConflict, 'Укажите причину отмены.', fn () => $this->service->cancelSession( self::ACTOR, self::SESSION, ' ', 1 ) );
	}

	public function test_cancelled_session_only_finishes_remaining_registrations(): void {
		$this->session = $this->examSession( array( 'status' => 'cancelled' ) );
		$this->sessions->expects( self::never() )->method( 'update' );
		$this->registrations->method( 'listBySession' )->willReturn( array( $this->registration( 21 ) ) );
		$this->registrationService->expects( self::once() )->method( 'cancelByStaff' )->with( self::ACTOR, 21, 'Авария' );

		$this->service->cancelSession( self::ACTOR, self::SESSION, 'Авария', 1 );
	}

	public function test_complete_when_all_sessions_ended_and_no_active_attempts(): void {
		$this->event       = $this->examEvent( array( 'status' => 'published' ) );
		$this->now         = array( 'utc' => '2026-03-10 12:00:00', 'local' => '2026-03-10 15:00:00' );
		$this->sessionList = array( $this->session );
		$this->registrations->method( 'countActiveByEvent' )->willReturn( 0 );
		$this->attempts->method( 'countInProgressExamByEventAll' )->willReturn( 0 );
		$this->sessions->expects( self::once() )->method( 'completeEndedByEvent' )->with( self::EVENT, '2026-03-10 12:00:00' );
		$this->events->expects( self::once() )->method( 'update' )->with(
			self::EVENT,
			array( 'status' => 'completed', 'completed_at' => '2026-03-10 12:00:00' ),
			1
		)->willReturn( true );

		self::assertTrue( $this->service->completeIfDone( self::EVENT ) );
	}

	public function test_not_completed_while_attempt_in_progress(): void {
		$this->event       = $this->examEvent( array( 'status' => 'published' ) );
		$this->now         = array( 'utc' => '2026-03-10 12:00:00', 'local' => '2026-03-10 15:00:00' );
		$this->sessionList = array( $this->session );
		$this->registrations->method( 'countActiveByEvent' )->willReturn( 0 );
		$this->attempts->method( 'countInProgressExamByEventAll' )->willReturn( 1 );
		$this->events->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->completeIfDone( self::EVENT ) );
	}

	public function test_not_completed_while_a_session_has_not_ended(): void {
		$this->event       = $this->examEvent( array( 'status' => 'published' ) );
		$this->now         = array( 'utc' => '2026-03-10 09:00:00', 'local' => '2026-03-10 12:00:00' );
		$this->sessionList = array( $this->session );
		$this->events->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->completeIfDone( self::EVENT ) );
	}

	public function test_not_completed_with_active_registration_without_outcome(): void {
		$this->event       = $this->examEvent( array( 'status' => 'published' ) );
		$this->now         = array( 'utc' => '2026-03-10 12:00:00', 'local' => '2026-03-10 15:00:00' );
		$this->sessionList = array( $this->session );
		$this->registrations->method( 'countActiveByEvent' )->willReturn( 1 );
		$this->events->expects( self::never() )->method( 'update' );

		self::assertFalse( $this->service->completeIfDone( self::EVENT ) );
	}

	/** @return array<string, mixed> */
	private function moveInput( array $override = array() ): array {
		return array_merge( array( 'date' => '2026-03-11', 'time' => '12:00', 'room_id' => 2 ), $override );
	}

	private function registration( int $id ): \Inc\DTO\Exam\ExamRegistrationDTO {
		return \Inc\DTO\Exam\ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => 1, 'session_id' => self::SESSION, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_cancel_with_stale_version_fails(): void {
		$this->sessions->expects( self::never() )->method( 'cancelOpenByEvent' );

		$this->assertRefusal( ErrorCode::ExamStale, self::STALE, fn () => $this->service->cancelEvent( self::ACTOR, self::EVENT, 'Причина', 7 ) );
	}

	public function test_rebuild_snapshot_blocked_during_running_session(): void {
		$this->now         = array( 'utc' => '2026-03-10 08:00:00', 'local' => '2026-03-10 11:00:00' );
		$this->sessionList = array( $this->session );
		$this->events->expects( self::never() )->method( 'update' );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Идёт сеанс с этим вариантом: снимок менять нельзя.', fn () => $this->service->rebuildSnapshot( self::ACTOR, self::EVENT, self::ASSESSMENT ) );
	}

	public function test_rebuild_snapshot_blocked_by_unfinished_attempts(): void {
		$this->now         = array( 'utc' => '2026-03-10 11:30:00', 'local' => '2026-03-10 14:30:00' );
		$this->sessionList = array( $this->session );
		$this->attempts->method( 'countInProgressExamByEvent' )->with( self::EVENT, self::ASSESSMENT )->willReturn( 1 );

		$this->assertRefusal( ErrorCode::ExamConflict, 'Идёт сеанс с этим вариантом: снимок менять нельзя.', fn () => $this->service->rebuildSnapshot( self::ACTOR, self::EVENT, self::ASSESSMENT ) );
	}

	public function test_rebuild_snapshot_requires_variant_in_event(): void {
		$this->sessionList = array( $this->session );

		$this->assertRefusal( ErrorCode::ExamConflict, 'В проведении нет сеансов с этим вариантом.', fn () => $this->service->rebuildSnapshot( self::ACTOR, self::EVENT, 999 ) );
	}

	public function test_rebuild_snapshot_replaces_only_that_variant(): void {
		$this->event       = $this->examEvent( array( 'status' => 'published', 'variant_snapshot' => (string) json_encode( array( '501' => array( 'duration_minutes' => 150 ) ) ) ) );
		$this->now         = array( 'utc' => '2026-03-10 11:30:00', 'local' => '2026-03-10 14:30:00' );
		$this->sessionList = array( $this->session );
		$this->events->expects( self::once() )->method( 'update' )->with(
			self::EVENT,
			self::callback( static function ( array $d ): bool {
				$snapshot = json_decode( $d['variant_snapshot'], true );
				return 150 === $snapshot['501']['duration_minutes'] && 235 === $snapshot['500']['duration_minutes'];
			} ),
			1
		);

		$this->service->rebuildSnapshot( self::ACTOR, self::EVENT, self::ASSESSMENT );
	}

	public function test_sync_capacity_increase_applies(): void {
		$session = $this->examSession( array( 'capacity' => '12', 'occupied_count' => '5' ) );
		$this->sessions->method( 'listFutureOpenByRoom' )->with( 2, '2026-03-01 07:00:00' )->willReturn( array( $session ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::SESSION => $session ) );
		$this->sessions->expects( self::once() )->method( 'setCapacity' )->with( self::SESSION, 20 );

		$this->service->syncCapacityForRoom( 2, 20 );
	}

	public function test_sync_capacity_decrease_to_occupied_is_allowed(): void {
		$session = $this->examSession( array( 'capacity' => '12', 'occupied_count' => '8' ) );
		$this->sessions->method( 'listFutureOpenByRoom' )->willReturn( array( $session ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::SESSION => $session ) );
		$this->sessions->expects( self::once() )->method( 'setCapacity' )->with( self::SESSION, 8 );

		$this->service->syncCapacityForRoom( 2, 8 );
	}

	public function test_sync_capacity_does_not_touch_session_with_same_capacity(): void {
		$session = $this->examSession( array( 'capacity' => '12' ) );
		$this->sessions->method( 'listFutureOpenByRoom' )->willReturn( array( $session ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::SESSION => $session ) );
		$this->sessions->expects( self::never() )->method( 'setCapacity' );

		$this->service->syncCapacityForRoom( 2, 12 );
	}

	public function test_sync_capacity_decrease_below_occupied_throws(): void {
		$session = $this->examSession( array( 'capacity' => '12', 'occupied_count' => '9' ) );
		$this->sessions->method( 'listFutureOpenByRoom' )->willReturn( array( $session ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::SESSION => $session ) );
		$this->sessions->expects( self::never() )->method( 'setCapacity' );
		$persisted = false;

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Нельзя уменьшить вместимость до 8: в проведении «Пробный ЕГЭ» уже занято 9 мест.',
			function () use ( &$persisted ): void {
				$this->service->syncCapacityForRoom( 2, 8, function () use ( &$persisted ): void {
					$persisted = true;
				} );
			}
		);
		self::assertFalse( $persisted, 'Кабинет не сохраняется, если синхронизация отказала.' );
		self::assertContains( 'ROLLBACK', $this->db->log );
	}

	public function test_sync_capacity_rejects_zero_seats_while_sessions_are_planned(): void {
		$session = $this->examSession( array( 'occupied_count' => '0' ) );
		$this->sessions->method( 'listFutureOpenByRoom' )->willReturn( array( $session ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::SESSION => $session ) );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'В кабинете запланирован сеанс проведения «Пробный ЕГЭ»: вместимость не может быть нулевой.',
			fn () => $this->service->syncCapacityForRoom( 2, 0 )
		);
	}

	public function test_sync_capacity_saves_room_in_same_transaction_after_sessions(): void {
		$session = $this->examSession( array( 'capacity' => '12' ) );
		$this->sessions->method( 'listFutureOpenByRoom' )->willReturn( array( $session ) );
		$this->sessions->method( 'lockInOrder' )->willReturn( array( self::SESSION => $session ) );
		$this->sessions->method( 'setCapacity' )->willReturnCallback( function (): void {
			$this->db->log[] = 'set-capacity';
		} );

		$this->service->syncCapacityForRoom( 2, 20, function (): void {
			$this->db->log[] = 'room-save';
		} );

		self::assertSame( array( 'START TRANSACTION', 'room-lock', 'set-capacity', 'room-save', 'COMMIT' ), $this->db->log );
	}

	public function test_sync_capacity_without_sessions_still_saves_room(): void {
		$persisted = false;

		$this->service->syncCapacityForRoom( 2, 20, function () use ( &$persisted ): void {
			$persisted = true;
		} );

		self::assertTrue( $persisted );
	}

	public function test_guest_event_publish_blocked_until_checklist_ready(): void {
		$this->event       = $this->examEvent( array( 'guest_registration_enabled' => '1' ) );
		$this->sessionList = array( $this->session );
		$checklist         = $this->createMock( ExamLaunchChecklist::class );
		$checklist->method( 'isReady' )->willReturn( false );
		$checklist->method( 'failedSummary' )->willReturn( 'Включите WooCommerce.' );
		$this->checklist = $checklist;
		$this->events->expects( self::never() )->method( 'update' );

		$this->assertRefusal(
			ErrorCode::ExamConflict,
			'Запись гостей недоступна: настройки запуска не завершены. Включите WooCommerce.',
			fn () => $this->makeService()->publish( self::ACTOR, self::EVENT, 1 )
		);
	}

	public function test_non_guest_event_ignores_checklist(): void {
		$this->event       = $this->examEvent( array( 'guest_registration_enabled' => '0' ) );
		$this->sessionList = array( $this->session );
		$checklist         = $this->createMock( ExamLaunchChecklist::class );
		$checklist->expects( self::never() )->method( 'isReady' );
		$this->checklist = $checklist;

		$this->makeService()->publish( self::ACTOR, self::EVENT, 1 );

		self::addToAssertionCount( 1 );
	}
}
