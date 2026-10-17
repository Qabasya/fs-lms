<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;


use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamResultsViewService;
use Inc\Services\Exam\ExamScoreService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/** Список результатов: поля, фильтры и область доступа. */
#[AllowMockObjectsWithoutExpectations]
class ExamResultsViewServiceTest extends TestCase {

	use ExamFixtures;

	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamParticipationRepository&MockObject $participations;
	private ExamParticipantRepository&MockObject $participants;
	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentAnswerRepository&MockObject $answers;
	private StudentRecordRepository&MockObject $records;
	private ExamScoreService&MockObject $scores;
	private ExamAccessGuard&MockObject $guard;
	private \Inc\Services\Exam\GuestParticipantMaterializer&MockObject $guestData;
	private ExamSourceRepository&MockObject $sources;
	private ExamTime&MockObject $time;
	private $groupsRepo;
	private ExamResultsViewService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->answers        = $this->createMock( AssessmentAnswerRepository::class );
		$this->records        = $this->createMock( StudentRecordRepository::class );
		$this->scores         = $this->createMock( ExamScoreService::class );
		$this->guard          = $this->createMock( ExamAccessGuard::class );
		$this->guestData      = $this->createMock( \Inc\Services\Exam\GuestParticipantMaterializer::class );
		$this->sources        = $this->createMock( ExamSourceRepository::class );
		$this->groupsRepo     = $this->createMock( \Inc\Repositories\WPDBRepositories\GroupsRepository::class );
		$this->time           = $this->createMock( ExamTime::class );

		$this->time->method( 'nowUtc' )->willReturn( '2026-03-10 08:00:00' );
		$this->time->method( 'nowLocal' )->willReturn( '2026-03-10 11:00:00' );
		$this->time->method( 'toLocal' )->willReturnCallback( static fn ( string $utc ): string => gmdate( 'Y-m-d H:i:s', strtotime( $utc ) + 3 * 3600 ) );
		$this->time->method( 'toUtc' )->willReturnCallback( static fn ( string $local ): string => gmdate( 'Y-m-d H:i:s', strtotime( $local ) - 3 * 3600 ) );
		$this->time->method( 'secondsUntil' )->willReturnCallback( static fn ( string $from, string $to ): int => strtotime( $to ) - strtotime( $from ) );

		$this->guard->method( 'canManageEvent' )->willReturn( true );
		$this->guard->method( 'canManageEventGuests' )->willReturn( true );
		$this->sessions->method( 'find' )->willReturn( $this->examSession() );
		$this->sessions->method( 'findByEvent' )->willReturn( array( $this->examSession() ) );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published' ) ) );
		$this->scores->method( 'summarize' )->willReturn( array( 'primary' => 10, 'primary_max' => 20, 'secondary' => null, 'grade' => null, 'pending' => false ) );

		$this->service = $this->viewService( $this->guard );
	}

	private function viewService( ExamAccessGuard $guard ): ExamResultsViewService {
		return new ExamResultsViewService(
			$this->events, $this->sessions, $this->registrations, $this->participations, $this->participants, $this->attempts,
			$this->sources, new \Inc\Services\Exam\ExamParticipantNameService( $this->records, $this->guestData ),
			$this->scores, $guard, $this->time,
			new \Inc\Services\Exam\ExamResultStatusService( $this->answers ),
			new \Inc\Services\Exam\ExamEventViewService( $this->sessions, $this->sources, $this->registrations, $this->time ),
			new \Inc\Services\Exam\ExamParticipantProfileService( $this->records, $this->sources, $this->groupsRepo ),
		);
	}

	private function registration( int $id, int $participationId, string $status = 'confirmed', ?string $arrivedAt = null ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => $participationId, 'session_id' => 7, 'status' => $status,
			'active_slot' => 'confirmed' === $status ? 1 : null, 'arrived_at' => $arrivedAt, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	private function participation( int $id, ?int $attemptId = null, string $audience = 'student' ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => $id, 'event_id' => 3, 'participant_id' => $id + 100, 'audience' => $audience, 'current_attempt_id' => $attemptId,
			'transfer_allowed' => 0, 'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function participant( int $id, ?int $personId ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => $id, 'person_id' => $personId, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
	}

	private function attempt( int $id, int $registrationId, string $status, ?string $approvedAt = null, int $participationId = 1 ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => $id, 'assessment_id' => 500, 'student_person_id' => 1, 'attempt_number' => 1,
			'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 13:55:00', 'status' => $status,
			'approved_at' => $approvedAt, 'exam_participation_id' => $participationId, 'exam_registration_id' => $registrationId,
		) );
	}

	/* ── 8.7 Результаты ───────────────────────────────────────────────────── */

	/** Три участия проведения: 1 — работа готова, 2 — ждёт проверки; 3 — гость с готовой работой. */
	private function givenResults( ?string $school = null ): void {
		$this->events->method( 'findBySubjectKey' )->willReturn( array( $this->examEvent( array( 'status' => 'published' ) ) ) );
		$p = array( 1 => $this->participation( 1, 11 ), 2 => $this->participation( 2, 12 ), 3 => $this->participation( 3, 13, 'guest' ) );
		$this->participations->method( 'findByEvent' )->willReturn( array_values( $p ) );
		$this->attempts->method( 'listByParticipations' )->willReturn( array(
			$this->attempt( 11, 1, 'graded', null, 1 ), $this->attempt( 12, 2, 'submitted', null, 2 ), $this->attempt( 13, 3, 'graded', null, 3 ),
		) );
		$this->participants->method( 'find' )->willReturnCallback( fn ( int $id ): ExamParticipantDTO => $this->participant( $id, $id > 102 ? null : $id - 100 ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration( 1, 1 ) );
		$this->records->method( 'findActiveByStudentFirst' )->willReturn( StudentRecordDTO::fromArray( array(
			'id' => 1, 'student_person_id' => 1, 'snapshot_last_name' => 'Иванов', 'snapshot_first_name' => 'Пётр', 'snapshot_school' => $school, 'status' => 'active',
		) ) );
		$this->answers->method( 'hasPendingAnswers' )->willReturnCallback( static fn ( int $id ): bool => 12 === $id );
	}

	public function test_results_filter_by_status(): void {
		$this->givenResults();

		$pending = $this->service->results( 10, 'inf_ege', array( 'status' => 'pending_review' ) )['items'];
		$ready   = $this->service->results( 10, 'inf_ege', array( 'status' => 'ready' ) )['items'];
		$all     = $this->service->results( 10, 'inf_ege', array( 'status' => 'all' ) )['items'];

		self::assertSame( array( 12 ), array_column( $pending, 'attempt_id' ) );
		self::assertSame( array( 11 ), array_column( $ready, 'attempt_id' ) );
		self::assertCount( 3, $all );
	}

	public function test_results_filter_by_audience_and_source(): void {
		$this->givenResults();

		$guests   = $this->service->results( 10, 'inf_ege', array( 'audience' => 'guest' ) )['items'];
		$students = $this->service->results( 10, 'inf_ege', array( 'audience' => 'student' ) )['items'];
		$noSource = $this->service->results( 10, 'inf_ege', array( 'source_id' => 99 ) )['items'];

		self::assertSame( array( 13 ), array_column( $guests, 'attempt_id' ) );
		self::assertSame( array( 11, 12 ), array_column( $students, 'attempt_id' ) );
		self::assertSame( array(), $noSource );
	}

	public function test_group_filter_applies_to_students_only_and_hides_guests(): void {
		$this->groupsRepo->method( 'findById' )->willReturn( (object) array( 'id' => 5, 'name' => 'Группа А' ) );
		$this->givenResults();

		$result = $this->service->results( 10, 'inf_ege', array( 'group_id' => 5 ) );

		self::assertSame( array( 11, 12 ), array_column( $result['items'], 'attempt_id' ), 'Гость под фильтром группы не показывается.' );
		self::assertSame( array( array( 'id' => 5, 'label' => 'Группа А' ) ), $result['filters']['groups'] );
		self::assertSame( array(), $this->service->results( 10, 'inf_ege', array( 'group_id' => 6 ) )['items'] );
	}

	public function test_source_filter_keeps_only_its_guests(): void {
		$this->givenResults();
		$result = $this->service->results( 10, 'inf_ege', array( 'source_id' => 0, 'audience' => 'all' ) );
		self::assertCount( 3, $result['items'] );

		// Ученики источника не имеют; фильтр источника скрывает их, а не оставляет «заодно».
		self::assertSame( array(), $this->service->results( 10, 'inf_ege', array( 'source_id' => 99 ) )['items'] );
	}

	public function test_school_filter_applies_to_everyone_and_options_ignore_other_filters(): void {
		$this->givenResults( 'Лицей № 5' );

		$all    = $this->service->results( 10, 'inf_ege', array() );
		$byName = $this->service->results( 10, 'inf_ege', array( 'school' => 'Лицей № 5' ) );
		$other  = $this->service->results( 10, 'inf_ege', array( 'school' => 'Школа № 9' ) );

		self::assertSame( array( 'Лицей № 5' ), $all['filters']['schools'] );
		self::assertSame( array( 11, 12 ), array_column( $byName['items'], 'attempt_id' ), 'У гостя без источника школы нет — под фильтр не попадает.' );
		self::assertSame( array(), $other['items'] );
		self::assertSame( array( 'Лицей № 5' ), $other['filters']['schools'], 'Список школ не сужается выбранной школой.' );
	}

	public function test_results_exclude_foreign_events(): void {
		$this->events->method( 'findBySubjectKey' )->willReturn( array( $this->examEvent( array( 'status' => 'published' ) ) ) );
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$service = $this->viewService( $guard );
		$this->participations->expects( self::never() )->method( 'findByEvent' );

		$result = $service->results( 99, 'inf_ege', array() );

		self::assertSame( array(), $result['items'] );
		self::assertSame( array(), $result['filters']['events'] );
	}

	public function test_result_row_contract_and_guest_label_are_preserved(): void {
		$this->givenResults();
		$result = $this->service->results( 10, 'inf_ege', array() );
		$row = $result['items'][0];
		self::assertSame( array(
			'attempt_id', 'participation_id', 'result_version', 'event_id', 'event_title',
			'session_id', 'session_date', 'session_time', 'name', 'audience', 'audience_label',
			'source', 'school', 'source_id', 'group_id', 'group', 'result_status', 'result_status_label', 'score',
		), array_keys( $row ) );
		self::assertSame( 'Иванов Пётр', $row['name'] );
		self::assertSame( 'ready', $row['result_status'] );
		self::assertSame( 'Готова к утверждению', $row['result_status_label'] );
		self::assertSame( 'Результат выдан', $result['items'][2]['result_status_label'] );
		self::assertSame( 10, $row['score']['primary'] );
		self::assertSame( array( 'events', 'sessions', 'sources', 'groups', 'schools' ), array_keys( $result['filters'] ) );
	}

	public function test_event_and_session_filters_do_not_expand_selection(): void {
		$this->givenResults();
		self::assertCount( 3, $this->service->results( 10, 'inf_ege', array( 'event_id' => 3, 'session_id' => 7 ) )['items'] );
		self::assertSame( array(), $this->service->results( 10, 'inf_ege', array( 'session_id' => 99 ) )['items'] );
		$result = $this->service->results( 10, 'inf_ege', array( 'event_id' => 99 ) );
		self::assertSame( array(), $result['items'] );
		self::assertSame( array(), $result['filters']['sessions'] );
		self::assertSame( array(), $result['filters']['sources'] );
	}

	public function test_only_published_and_completed_events_enter_filters(): void {
		$this->events->expects( self::once() )->method( 'findBySubjectKey' )->with( 'inf_ege' )->willReturn( array(
			$this->examEvent( array( 'id' => 3, 'status' => 'published' ) ),
			$this->examEvent( array( 'id' => 4, 'status' => 'completed' ) ),
			$this->examEvent( array( 'id' => 5, 'status' => 'draft' ) ),
			$this->examEvent( array( 'id' => 6, 'status' => 'cancelled' ) ),
		) );
		$ids = array();
		$this->participations->method( 'findByEvent' )->willReturnCallback( static function ( int $id ) use ( &$ids ): array {
			$ids[] = $id;
			return array();
		} );
		$result = $this->service->results( 10, 'inf_ege', array() );
		self::assertSame( array( 3, 4 ), $ids );
		self::assertSame( array( 3, 4 ), array_column( $result['filters']['events'], 'id' ) );
	}

}
