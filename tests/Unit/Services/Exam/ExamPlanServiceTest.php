<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Course\RoomDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Log\ErrorCode;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamPlanService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\ExamVariantPolicy;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Экран «Назначить экзамен»: какие проведения видит пользователь, какие кабинеты предлагаются, в каком времени уходят данные.
 * Сайт — Москва (+3): 07:00 UTC — 10:00 местного.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamPlanServiceTest extends TestCase {

	use ExamFixtures;

	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamAccessGuard&MockObject $guard;
	private ExamVariantPolicy&MockObject $variants;
	private RoomRepository&MockObject $rooms;
	private AssessmentManager&MockObject $assessments;
	private ExamPlanService $service;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow';

		$this->events      = $this->createMock( ExamEventRepository::class );
		$this->sessions    = $this->createMock( ExamSessionRepository::class );
		$this->guard       = $this->createMock( ExamAccessGuard::class );
		$this->variants    = $this->createMock( ExamVariantPolicy::class );
		$this->rooms       = $this->createMock( RoomRepository::class );
		$this->assessments = $this->createMock( AssessmentManager::class );

		$this->guard->method( 'canManageSubject' )->willReturn( true );
		$this->guard->method( 'canManageEvent' )->willReturn( true );

		$this->service = new ExamPlanService(
			$this->events, $this->sessions, $this->guard, $this->variants, $this->rooms, $this->assessments,
			new ExamTime( $this->createMock( ClockInterface::class ) )
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	/** @param array<string, mixed> $override */
	private function room( int $id, int $seats, string $subjects = '[]', string $active = '1' ): RoomDTO {
		return RoomDTO::fromArray( array( 'id' => (string) $id, 'name' => 'Каб. ' . $id, 'seats' => (string) $seats, 'allowed_subjects' => $subjects, 'is_active' => $active ) );
	}

	public function test_get_plan_denied_for_foreign_subject(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageSubject' )->with( 10, 'math' )->willReturn( false );
		$service = new ExamPlanService( $this->events, $this->sessions, $guard, $this->variants, $this->rooms, $this->assessments, new ExamTime( $this->createMock( ClockInterface::class ) ) );

		try {
			$service->build( 10, 'math' );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_teacher_gets_only_own_events(): void {
		$this->guard = $this->createMock( ExamAccessGuard::class );
		$this->guard->method( 'canManageSubject' )->willReturn( true );
		$this->guard->method( 'isGlobal' )->willReturn( false );
		$this->events->expects( self::never() )->method( 'findBySubjectKey' );
		$this->events->expects( self::once() )->method( 'listByOwner' )->with( 10 )->willReturn( array(
			$this->examEvent( array( 'id' => '1', 'subject_key' => 'inf_ege' ) ),
			$this->examEvent( array( 'id' => '2', 'subject_key' => 'python' ) ),
		) );
		$service = new ExamPlanService( $this->events, $this->sessions, $this->guard, $this->variants, $this->rooms, $this->assessments, new ExamTime( $this->createMock( ClockInterface::class ) ) );

		$plan = $service->build( 10, 'inf_ege' );

		self::assertSame( array( 1 ), array_column( $plan['events'], 'id' ), 'Чужой предмет и чужие проведения не попадают в список.' );
	}

	public function test_global_user_gets_all_events_of_subject(): void {
		$this->guard = $this->createMock( ExamAccessGuard::class );
		$this->guard->method( 'canManageSubject' )->willReturn( true );
		$this->guard->method( 'isGlobal' )->willReturn( true );
		$this->events->expects( self::never() )->method( 'listByOwner' );
		$this->events->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array(
			$this->examEvent( array( 'id' => '1', 'owner_user_id' => '10' ) ),
			$this->examEvent( array( 'id' => '2', 'owner_user_id' => '11' ) ),
		) );
		$service = new ExamPlanService( $this->events, $this->sessions, $this->guard, $this->variants, $this->rooms, $this->assessments, new ExamTime( $this->createMock( ClockInterface::class ) ) );

		self::assertSame( array( 1, 2 ), array_column( $service->build( 3, 'inf_ege' )['events'], 'id' ) );
	}

	public function test_first_available_event_is_selected_by_default(): void {
		$this->guard = $this->createMock( ExamAccessGuard::class );
		$this->guard->method( 'canManageSubject' )->willReturn( true );
		$this->guard->method( 'isGlobal' )->willReturn( true );
		$this->events->method( 'findBySubjectKey' )->willReturn( array( $this->examEvent( array( 'id' => '5', 'title' => 'Свежее' ) ), $this->examEvent( array( 'id' => '4' ) ) ) );
		$service = new ExamPlanService( $this->events, $this->sessions, $this->guard, $this->variants, $this->rooms, $this->assessments, new ExamTime( $this->createMock( ClockInterface::class ) ) );

		self::assertSame( 5, $service->build( 3, 'inf_ege' )['event']['id'] );
	}

	public function test_no_events_gives_null_event_and_empty_list(): void {
		$plan = $this->service->build( 10, 'inf_ege' );

		self::assertSame( array(), $plan['events'] );
		self::assertNull( $plan['event'] );
	}

	public function test_explicit_event_of_other_subject_is_denied(): void {
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'subject_key' => 'python' ) ) );

		$this->expectException( CodedException::class );

		$this->service->build( 10, 'inf_ege', 3 );
	}

	public function test_explicit_event_without_event_scope_is_denied(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageSubject' )->willReturn( true );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$this->events->method( 'find' )->willReturn( $this->examEvent() );
		$service = new ExamPlanService( $this->events, $this->sessions, $guard, $this->variants, $this->rooms, $this->assessments, new ExamTime( $this->createMock( ClockInterface::class ) ) );

		try {
			$service->build( 99, 'inf_ege', 3 );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamAccess, $e->errorCode );
		}
	}

	public function test_rooms_list_excludes_rooms_without_capacity(): void {
		$this->rooms->method( 'findAll' )->with( true )->willReturn( array(
			$this->room( 1, 20 ),
			$this->room( 2, 0 ),
			$this->room( 3, 12, '["python"]' ),
			$this->room( 4, 30, '["inf_ege"]' ),
		) );

		$rooms = $this->service->roomsFor( 'inf_ege' );

		self::assertSame( array( 1, 4 ), array_column( $rooms, 'id' ), 'Нулевая вместимость и чужой предмет отсекаются.' );
		self::assertSame( array( 'id' => 1, 'name' => 'Каб. 1', 'seats' => 20 ), $rooms[0] );
	}

	public function test_times_are_returned_in_site_local_time(): void {
		$event = $this->examEvent( array( 'registration_opens_at' => '2026-03-01 06:00:00', 'registration_closes_at' => '2026-03-09 15:00:00' ) );
		$this->sessions->method( 'findByEvent' )->willReturn( array( $this->examSession( array( 'scheduled_at' => '2026-03-10 07:00:00', 'planned_end_at' => '2026-03-10 10:55:00' ) ) ) );
		$this->rooms->method( 'find' )->willReturn( $this->room( 2, 12 ) );

		$payload = $this->service->eventPayload( $event );

		self::assertSame( '2026-03-01 09:00:00', $payload['registration_opens_at'] );
		self::assertSame( '2026-03-09 18:00:00', $payload['registration_closes_at'] );
		$session = $payload['sessions'][0];
		self::assertSame( '2026-03-10', $session['date'] );
		self::assertSame( '10:00', $session['time_start'], '07:00 UTC = 10:00 МСК.' );
		self::assertSame( '13:55', $session['time_end'] );
		self::assertSame( 'Каб. 2', $session['room_name'] );
	}

	public function test_session_payload_reports_lock_and_occupancy_and_variant_title(): void {
		$this->rooms->method( 'find' )->willReturn( $this->room( 2, 12 ) );
		$this->assessments->method( 'get' )->willReturn( new AssessmentDTO(
			id: 500, subjectKey: 'inf_ege', title: 'Демоверсия', taskIds: array(), timeLimit: 235, attemptsAllowed: 1, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: AssessmentKind::EgeComputer, taskPoints: array(), scoreMap: array()
		) );

		$payload = $this->service->sessionPayload( $this->examSession( array( 'occupied_count' => '4', 'first_started_at' => '2026-03-10 07:05:00' ) ) );

		self::assertTrue( $payload['is_locked'] );
		self::assertSame( 4, $payload['occupied'] );
		self::assertSame( 12, $payload['capacity'] );
		self::assertSame( 'Демоверсия', $payload['assessment_title'] );
	}

	public function test_missing_variant_does_not_break_the_session_row(): void {
		$this->rooms->method( 'find' )->willReturn( null );
		$this->assessments->method( 'get' )->willReturn( null );

		$payload = $this->service->sessionPayload( $this->examSession() );

		self::assertSame( 'Вариант удалён', $payload['assessment_title'] );
		self::assertSame( '', $payload['room_name'] );
	}

	public function test_variants_come_from_policy_with_duration(): void {
		$this->variants->method( 'listForSubject' )->with( 'inf_ege' )->willReturn( array( array( 'id' => 500, 'title' => 'Демоверсия', 'kind' => 'ege_computer', 'direction' => 'ege', 'duration_minutes' => 235 ) ) );

		self::assertSame( 235, $this->service->build( 10, 'inf_ege' )['variants'][0]['duration_minutes'] );
	}

	public function test_status_label_comes_from_enum(): void {
		$payload = $this->service->eventPayload( $this->examEvent( array( 'status' => 'published' ) ) );

		self::assertSame( 'Опубликовано', $payload['status_label'] );
	}
}
