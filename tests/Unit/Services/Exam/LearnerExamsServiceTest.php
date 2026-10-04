<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\ClockInterface;
use Inc\DTO\Assessment\AssessmentDTO;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamEventDTO;
use Inc\DTO\Exam\ExamFormatDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSessionDTO;
use Inc\Enums\Assessment\AssessmentKind;
use Inc\Enums\Assessment\ScoringPolicy;
use Inc\Enums\Exam\ExamDirection;
use Inc\Managers\Assessment\AssessmentManager;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Exam\ExamFormatRegistry;
use Inc\Services\Exam\ExamNoShowService;
use Inc\Services\Exam\ExamReviewProjection;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\LearnerExamsService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Карточки «Моих экзаменов»: состояние и разрешённые действия считает сервер; запуск — только ученику
 * и только в «Приступить»/«Продолжить»; итог — только раскрытому результату.
 *
 * Время теста: сеанс 07:00–10:55 UTC; если не сказано иное, местное время сайта совпадает с UTC.
 * Репозитории — моки, управляемые свойствами теста (каталог проведений, сеансы, участия, записи): так одно состояние
 * переключается без пересоздания заглушек.
 */
#[AllowMockObjectsWithoutExpectations]
class LearnerExamsServiceTest extends TestCase {

	private const PERSON = 3;

	/** Ключи, которых в карточке не должно быть ни в одном состоянии: баллы, эталоны, решения, состав заданий. */
	private const FORBIDDEN_KEYS = array( 'score', 'total_score', 'correct', 'tasks', 'answers', 'answer', 'solution', 'reference', 'unit_key', 'max' );

	private ExamAudienceResolver&MockObject $audience;
	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamParticipationRepository&MockObject $participations;
	private ExamRegistrationRepository&MockObject $registrations;
	private AssessmentAttemptRepository&MockObject $attempts;
	private AssessmentManager&MockObject $assessments;
	private ExamReviewProjection&MockObject $reviews;
	private ExamFormatRegistry&MockObject $formats;
	private ExamNoShowService&MockObject $noShow;
	private LearnerExamsService $service;

	private string $now = '2026-03-12 07:30:00';

	/** @var string[] Предметы ученика по группам (`subjectKeysForStudent()`). */
	private array $subjectKeys = array( 'inf' );
	/** @var string[] Статусы, с которыми сервис последний раз спросил проведения у репозитория. */
	private array $requestedStatuses = array();
	/** @var ExamEventDTO[] Все проведения «базы»: репозиторий отдаёт их по предметам и статусам так же, как SQL. */
	private array $catalog = array();
	/** @var int[] Проведения, где у ученика уже есть участие. */
	private array $participationEventIds = array();
	/** @var ExamSessionDTO[] */
	private array $sessionList = array();
	/** @var array<int, ExamParticipationDTO> Участие ученика по ID проведения. */
	private array $participationsByEvent = array();
	/** @var array<int, ExamRegistrationDTO> Действующая запись по ID участия. */
	private array $activeByParticipation = array();
	/** @var array<int, ExamRegistrationDTO[]> Все записи участия по ID участия (последняя — в конце). */
	private array $historyByParticipation = array();
	/** @var array<string, mixed> Подмена полей проведения по умолчанию. */
	private array $eventOverride = array();

	protected function setUp(): void {
		parent::setUp();

		$this->audience       = $this->createMock( ExamAudienceResolver::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->assessments    = $this->createMock( AssessmentManager::class );
		$this->reviews        = $this->createMock( ExamReviewProjection::class );
		$this->formats        = $this->createMock( ExamFormatRegistry::class );
		$this->noShow         = $this->createMock( ExamNoShowService::class );

		$clock = $this->createMock( ClockInterface::class );
		$clock->method( 'now' )->willReturnCallback( fn (): string => $this->now );

		$this->service = new LearnerExamsService(
			$this->audience, $this->events, $this->sessions, $this->participations, $this->registrations, $this->attempts,
			$this->assessments, $this->createMock( RoomRepository::class ), $this->formats,
			$this->noShow, new ExamTime( $clock ), $this->reviews,
		);

		$this->sessionList = array( $this->session() );
		$this->catalog     = array( $this->event() );

		$this->audience->method( 'subjectKeysForStudent' )->willReturnCallback( fn (): array => $this->subjectKeys );
		// Репозиторий ведёт себя как SQL: проведения нужных предметов в нужных статусах.
		$this->events->method( 'findBySubjectsAndStatuses' )->willReturnCallback( function ( array $keys, array $statuses ): array {
			$this->requestedStatuses = $statuses;

			return array_values( array_filter(
				$this->catalog,
				static fn ( ExamEventDTO $e ): bool => in_array( $e->subjectKey, $keys, true ) && in_array( $e->status, $statuses, true )
			) );
		} );
		$this->events->method( 'findByIds' )->willReturnCallback(
			fn ( array $ids ): array => array_values( array_filter( $this->catalog, static fn ( ExamEventDTO $e ): bool => in_array( $e->id, $ids, true ) ) )
		);
		$this->participations->method( 'findEventIdsForPerson' )->willReturnCallback( fn (): array => $this->participationEventIds );
		$this->participations->method( 'findByEventAndPerson' )->willReturnCallback(
			fn ( int $eventId ): ?ExamParticipationDTO => $this->participationsByEvent[ $eventId ] ?? null
		);
		$this->participations->method( 'find' )->willReturnCallback( function ( int $id ): ?ExamParticipationDTO {
			foreach ( $this->participationsByEvent as $participation ) {
				if ( $participation->id === $id ) {
					return $participation;
				}
			}
			return null;
		} );
		$this->sessions->method( 'find' )->willReturnCallback(
			fn ( int $id ): ?ExamSessionDTO => array_values( array_filter( $this->sessionList, static fn ( ExamSessionDTO $x ): bool => $x->id === $id ) )[0] ?? null
		);
		$this->sessions->method( 'findByEvent' )->willReturnCallback(
			fn ( int $eventId ): array => array_values( array_filter( $this->sessionList, static fn ( ExamSessionDTO $x ): bool => $x->eventId === $eventId ) )
		);
		$this->registrations->method( 'findActive' )->willReturnCallback(
			fn ( int $participationId ): ?ExamRegistrationDTO => $this->activeByParticipation[ $participationId ] ?? null
		);
		$this->registrations->method( 'findByParticipation' )->willReturnCallback(
			fn ( int $participationId ): array => $this->historyByParticipation[ $participationId ] ?? array()
		);
		$this->assessments->method( 'examStationUrl' )->willReturnCallback(
			static fn ( int $assessmentId, int $registrationId ): string => "https://site.test/exam-{$assessmentId}/?exam_reg={$registrationId}"
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_fs_test_timezone'] );
		parent::tearDown();
	}

	/** @param array<string, mixed> $override */
	private function event( array $override = array() ): ExamEventDTO {
		return ExamEventDTO::fromArray( array_merge( array(
			'id' => 1, 'subject_key' => 'inf', 'title' => 'Экзамен', 'owner_user_id' => 0, 'status' => 'published',
			'period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'guest_registration_enabled' => 0, 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		), $this->eventOverride, $override ) );
	}

	/** @param array<string, mixed> $override */
	private function session( array $override = array() ): ExamSessionDTO {
		return ExamSessionDTO::fromArray( array_merge( array(
			'id' => 100, 'event_id' => 1, 'assessment_id' => 50, 'scheduled_at' => '2026-03-12 07:00:00', 'planned_end_at' => '2026-03-12 10:55:00',
			'room_id' => 0, 'capacity' => 10, 'occupied_count' => 1, 'responsible_user_id' => 1, 'status' => 'open', 'version' => 1,
			'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		), $override ) );
	}

	/** @param array<string, mixed> $override */
	private function participation( ?int $attemptId = null, int $version = 4, array $override = array() ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array_merge( array(
			'id' => 7, 'event_id' => 1, 'participant_id' => 4, 'audience' => 'student', 'current_attempt_id' => $attemptId,
			'transfer_allowed' => 0, 'version' => $version, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		), $override ) );
	}

	/** @param array<string, mixed> $override */
	private function registration( array $override = array() ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array_merge( array(
			'id' => 6, 'participation_id' => 7, 'session_id' => 100, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		), $override ) );
	}

	private function attempt( ?string $approvedAt = null, string $status = 'in_progress' ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 9, 'assessment_id' => 50, 'student_person_id' => self::PERSON, 'group_id' => null, 'attempt_number' => 1,
			'status' => $status, 'started_at' => '2026-03-12 07:10:00', 'deadline_at' => '2026-03-12 11:05:00',
			'approved_at' => $approvedAt, 'exam_participation_id' => 7, 'exam_registration_id' => 6,
		) );
	}

	/** Правка проведения по умолчанию (каталог собирается заново). @param array<string, mixed> $override */
	private function withEvent( array $override ): void {
		$this->eventOverride = $override;
		$this->catalog       = array( $this->event() );
	}

	/** @param ExamSessionDTO[] $sessions */
	private function withSessions( array $sessions ): void {
		$this->sessionList = $sessions;
	}

	/** Ученик записан на сеанс, попытки ещё нет (или она указана). */
	private function arrangeRegistered( ?int $attemptId = null, int $version = 4 ): void {
		$this->participationsByEvent[1] = $this->participation( $attemptId, $version );
		$this->activeByParticipation[7] = $this->registration();
	}

	/** Участие есть, действующей записи нет; в истории — указанные записи. @param ExamRegistrationDTO[] $history */
	private function arrangeWithoutActiveRegistration( array $history ): void {
		$this->participationsByEvent[1]   = $this->participation();
		$this->historyByParticipation[7]  = $history;
	}

	/** @return array<string, mixed> */
	private function card( bool $readOnly = false ): array {
		return $this->service->build( self::PERSON, $readOnly )['exams'][0];
	}

	/** Ни в карточке, ни глубже нет ключей из {@see FORBIDDEN_KEYS}. @param array<string, mixed> $card */
	private function assertNoLeakedKeys( array $card ): void {
		$found = array();
		$walk = static function ( array $node ) use ( &$walk, &$found ): void {
			foreach ( $node as $key => $value ) {
				$found[] = (string) $key;
				if ( is_array( $value ) ) {
					$walk( $value );
				}
			}
		};
		$walk( $card );

		self::assertSame( array(), array_values( array_intersect( array_unique( $found ), self::FORBIDDEN_KEYS ) ), 'В ответе нет баллов, эталонов и состава заданий.' );
	}

	// ---- запуск и продолжение (этап 6) -------------------------------------------------------------------------------------------

	public function test_entry_open_card_has_station_url_with_registration(): void {
		$this->arrangeRegistered();

		$card = $this->card();

		self::assertSame( 'entry_open', $card['state'] );
		self::assertSame( array( 'start' ), $card['actions'] );
		self::assertSame( 'https://site.test/exam-50/?exam_reg=6', $card['station_url'] );
	}

	public function test_state_in_progress(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$card = $this->card();

		self::assertSame( 'in_progress', $card['state'] );
		self::assertSame( 'Выполняется', $card['state_label'] );
		self::assertSame( array( 'resume' ), $card['actions'] );
		self::assertSame( 'https://site.test/exam-50/?exam_reg=6', $card['station_url'] );
		self::assertSame( '11:05', $card['deadline'] );
		self::assertSame( 12900, $card['seconds_left'], '07:30 → 11:05 = 3 ч 35 мин.' );
		$this->assertNoLeakedKeys( $card );
	}

	public function test_registered_card_before_start_has_no_station_url(): void {
		$this->now = '2026-03-12 06:00:00';
		$this->arrangeRegistered();

		$card = $this->card();

		self::assertSame( 'registered', $card['state'] );
		self::assertArrayNotHasKey( 'station_url', $card );
	}

	public function test_station_url_present_only_for_entry_open_and_in_progress(): void {
		$attempt = null;
		$this->attempts->method( 'find' )->willReturnCallback( static function () use ( &$attempt ): ?AttemptDTO {
			return $attempt;
		} );
		$this->reviews->method( 'forStudent' )->willReturn( array( 'revealed' => true, 'result' => array(), 'units' => array() ) );

		$cases = array(
			'open'              => array( '2026-03-02 09:00:00', null, null ),
			'registered'        => array( '2026-03-12 06:00:00', 'registered', null ),
			'entry_open'        => array( '2026-03-12 07:30:00', 'registered', null ),
			'in_progress'       => array( '2026-03-12 07:30:00', 'attempt', $this->attempt() ),
			'awaiting_approval' => array( '2026-03-12 11:30:00', 'attempt', $this->attempt( null, 'submitted' ) ),
			'approved'          => array( '2026-03-13 11:30:00', 'attempt', $this->attempt( '2026-03-13 10:00:00', 'graded' ) ),
		);

		foreach ( $cases as $state => list( $now, $registration, $current ) ) {
			$this->now     = $now;
			$attempt       = $current;
			$this->participationsByEvent = array();
			$this->activeByParticipation = array();
			if ( null !== $registration ) {
				$this->arrangeRegistered( null !== $current ? 9 : null );
			}

			$card = $this->card();

			self::assertSame( $state, $card['state'], $state );
			self::assertSame( in_array( $state, array( 'entry_open', 'in_progress' ), true ), array_key_exists( 'station_url', $card ), "station_url в состоянии {$state}" );
		}
	}

	public function test_in_progress_card_has_personal_deadline(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$card = $this->card();

		self::assertSame( '11:05', $card['deadline'], 'Личный дедлайн — от старта попытки, а не плановый конец сеанса (10:55).' );
		self::assertGreaterThan( 0, $card['seconds_left'] );
	}

	public function test_parent_never_gets_station_url(): void {
		$attempt = null;
		$this->attempts->method( 'find' )->willReturnCallback( static function () use ( &$attempt ): ?AttemptDTO {
			return $attempt;
		} );

		// «Приступить» (попытки нет) и «Продолжить» (попытка идёт): ученику ссылка на станцию есть, родителю — нет ни в одном.
		foreach ( array( 'entry_open' => null, 'in_progress' => $this->attempt() ) as $state => $current ) {
			$attempt = $current;
			$this->arrangeRegistered( null !== $current ? 9 : null );

			self::assertSame( $state, $this->card()['state'] );
			self::assertArrayHasKey( 'station_url', $this->card() );
			self::assertArrayNotHasKey( 'station_url', $this->card( true ), $state );
		}
	}

	public function test_parent_never_gets_station_url_or_actions(): void {
		$this->arrangeRegistered();

		$card = $this->card( true );

		self::assertSame( 'entry_open', $card['state'] );
		self::assertSame( array(), $card['actions'] );
		self::assertArrayNotHasKey( 'station_url', $card );
	}

	public function test_parent_of_approved_exam_keeps_only_results_action(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-13 10:00:00', 'graded' ) );
		$this->reviews->method( 'forStudent' )->willReturn( array( 'revealed' => true, 'result' => array( 'primary' => 18 ), 'units' => array() ) );

		$card = $this->card( true );

		self::assertSame( 'approved', $card['state'] );
		self::assertSame( array( 'results' ), $card['actions'] );
		self::assertArrayNotHasKey( 'station_url', $card );
	}

	public function test_parent_gets_no_deadline_of_running_attempt(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$card = $this->card( true );

		self::assertArrayNotHasKey( 'deadline', $card );
		self::assertArrayNotHasKey( 'seconds_left', $card );
	}

	public function test_state_awaiting_approval_has_no_scores(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( null, 'submitted' ) );
		$this->reviews->expects( self::never() )->method( 'forStudent' );

		$card = $this->card();

		self::assertSame( 'awaiting_approval', $card['state'] );
		self::assertSame( array(), $card['actions'] );
		foreach ( array( 'station_url', 'result', 'units', 'approved_at', 'deadline' ) as $key ) {
			self::assertArrayNotHasKey( $key, $card, "До утверждения в карточке не должно быть {$key}" );
		}
		$this->assertNoLeakedKeys( $card );
	}

	public function test_state_approved(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-13 10:00:00', 'graded' ) );
		$this->reviews->method( 'forStudent' )->willReturn( array(
			'revealed'    => true,
			'result'      => array( 'primary' => 18, 'primary_max' => 29 ),
			'approved_at' => '2026-03-13 10:00:00',
			'units'       => array( array( 'number' => '14', 'status' => 'correct', 'anchor' => 'u-abc', 'score' => 1.0, 'max' => 1.0, 'unit_key' => 'n:14' ) ),
		) );

		$card = $this->card();

		self::assertSame( 'approved', $card['state'] );
		self::assertSame( array( 'results' ), $card['actions'] );
		self::assertSame( 18, $card['result']['primary'] );
		self::assertSame( array( array( 'number' => '14', 'status' => 'correct', 'anchor' => 'u-abc' ) ), $card['units'], 'Наружу — только номер, статус и якорь.' );
		self::assertArrayNotHasKey( 'station_url', $card );
		$this->assertNoLeakedKeys( $card );
	}

	public function test_unapproved_card_has_no_result_keys(): void {
		// Проверена автоматически (graded), но преподаватель ещё не утвердил: ни итога, ни перечня заданий, ни времени утверждения.
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( null, 'graded' ) );
		$this->reviews->expects( self::never() )->method( 'forStudent' );

		$card = $this->card();

		self::assertSame( 'awaiting_approval', $card['state'] );
		foreach ( array( 'result', 'units', 'approved_at', 'station_url', 'deadline', 'seconds_left' ) as $key ) {
			self::assertArrayNotHasKey( $key, $card, $key );
		}
		$this->assertNoLeakedKeys( $card );
	}

	public function test_unrevealed_review_adds_no_result_to_an_approved_card(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-13 10:00:00', 'graded' ) );
		$this->reviews->method( 'forStudent' )->willReturn( array( 'revealed' => false ) );

		$card = $this->card();

		self::assertArrayNotHasKey( 'result', $card );
		self::assertArrayNotHasKey( 'units', $card );
	}

	public function test_registration_block_carries_participation_version_for_stale_tab_protection(): void {
		$this->now = '2026-03-12 06:00:00';
		$this->arrangeRegistered( null, 11 );

		$card = $this->card();

		self::assertSame( 11, $card['registration']['version'] );
		self::assertSame( 6, $card['registration']['registration_id'] );
	}

	// ---- состояния записи (5.2.2) ------------------------------------------------------------------------------------------------

	public function test_state_not_open(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->withEvent( array( 'registration_opens_at' => '2026-03-05 06:00:00' ) );

		$card = $this->card();

		self::assertSame( 'not_open', $card['state'] );
		self::assertSame( 'Запись не открыта', $card['state_label'] );
		self::assertSame( array(), $card['actions'] );
		self::assertSame( '2026-03-05 06:00:00', $card['registration_opens_at'] );
	}

	public function test_state_open(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->withEvent( array( 'registration_opens_at' => '2026-03-01 06:00:00', 'registration_closes_at' => '2026-03-11 06:00:00' ) );

		$card = $this->card();

		self::assertSame( 'open', $card['state'] );
		self::assertSame( array( 'register' ), $card['actions'] );
		self::assertSame( 'Запись открыта', $card['state_label'] );
		$this->assertNoLeakedKeys( $card );
	}

	public function test_state_full_when_all_future_sessions_full(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->withSessions( array(
			$this->session( array( 'id' => 100, 'capacity' => 10, 'occupied_count' => 10 ) ),
			$this->session( array( 'id' => 101, 'capacity' => 5, 'occupied_count' => 5, 'scheduled_at' => '2026-03-13 07:00:00', 'planned_end_at' => '2026-03-13 10:55:00' ) ),
		) );

		$card = $this->card();

		self::assertSame( 'full', $card['state'] );
		self::assertSame( array(), $card['actions'] );
		self::assertCount( 2, $card['sessions'], 'Заполненные сеансы остаются в списке.' );
	}

	public function test_state_closed(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->withEvent( array( 'registration_closes_at' => '2026-03-02 08:59:59' ) );

		$card = $this->card();

		self::assertSame( 'closed', $card['state'] );
		self::assertSame( array(), $card['actions'] );
	}

	public function test_state_closed_when_there_are_no_future_sessions(): void {
		$this->now = '2026-03-13 09:00:00';

		self::assertSame( 'closed', $this->card()['state'], 'Единственный сеанс уже прошёл, записи у ученика нет.' );
	}

	public function test_state_registered_with_change_and_cancel(): void {
		$this->now = '2026-03-12 06:00:00';
		$this->arrangeRegistered();

		$card = $this->card();

		self::assertSame( 'registered', $card['state'] );
		self::assertSame( array( 'change', 'cancel' ), $card['actions'] );
		self::assertSame( 100, $card['registration']['session_id'] );
		self::assertSame( '07:00', $card['registration']['time_start'] );
		self::assertTrue( $card['sessions'][0]['is_current'], 'Текущий сеанс отмечен для карусели.' );
	}

	public function test_registered_card_has_no_change_or_cancel_when_the_window_is_closed(): void {
		$this->now = '2026-03-12 06:00:00';
		$this->withEvent( array( 'registration_closes_at' => '2026-03-12 05:00:00' ) );
		$this->arrangeRegistered();

		$card = $this->card();

		self::assertSame( 'registered', $card['state'] );
		self::assertSame( array(), $card['actions'] );
	}

	public function test_state_entry_open_at_scheduled_time(): void {
		$this->arrangeRegistered();

		foreach ( array( '2026-03-12 07:00:00', '2026-03-12 10:54:59' ) as $now ) {
			$this->now = $now;
			self::assertSame( 'entry_open', $this->card()['state'], $now );
		}
	}

	public function test_state_missed(): void {
		// Сеанс закончился, попытки нет: карточка сама проставляет неявку и сразу показывает «пропущено», не дожидаясь cron.
		$this->now = '2026-03-12 11:00:00';
		$this->withSessions( array(
			$this->session(),
			$this->session( array( 'id' => 101, 'scheduled_at' => '2026-03-14 07:00:00', 'planned_end_at' => '2026-03-14 10:55:00' ) ),
		) );
		$this->arrangeRegistered();
		$this->noShow->expects( self::once() )->method( 'markMissed' )->with( 6 )->willReturnCallback( function (): bool {
			unset( $this->activeByParticipation[7] );
			$this->historyByParticipation[7] = array( $this->registration( array( 'status' => 'missed', 'active_slot' => null, 'missed_at' => '2026-03-12 10:55:00' ) ) );
			return true;
		} );

		$card = $this->card();

		self::assertSame( 'missed', $card['state'] );
		self::assertSame( 'Экзамен пропущен', $card['state_label'] );
		self::assertSame( array( 'register' ), $card['actions'], 'Есть будущий сеанс со свободным местом.' );
		self::assertSame( '2026-03-12', $card['previous_date'] );
	}

	public function test_missed_card_has_no_register_action_when_nothing_is_left_to_register_to(): void {
		$this->now = '2026-03-12 11:00:00';
		$this->arrangeWithoutActiveRegistration( array( $this->registration( array( 'status' => 'missed', 'active_slot' => null ) ) ) );

		$card = $this->card();

		self::assertSame( 'missed', $card['state'] );
		self::assertSame( array(), $card['actions'] );
	}

	public function test_state_cancelled_by_staff_shows_reason_and_allows_new_registration(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->arrangeWithoutActiveRegistration( array( $this->registration( array(
			'status' => 'cancelled', 'active_slot' => null, 'actor_user_id' => 9, 'reason' => 'Болеет',
		) ) ) );

		$card = $this->card();

		self::assertSame( 'cancelled_by_staff', $card['state'] );
		self::assertSame( 'Болеет', $card['last_reason'] );
		self::assertSame( array( 'register' ), $card['actions'] );
	}

	public function test_self_cancelled_registration_gives_plain_open_state_without_reason(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->arrangeWithoutActiveRegistration( array( $this->registration( array( 'status' => 'cancelled', 'active_slot' => null, 'actor_user_id' => null ) ) ) );

		$card = $this->card();

		self::assertSame( 'open', $card['state'] );
		self::assertNull( $card['last_reason'] );
	}

	public function test_state_event_cancelled(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->withEvent( array( 'status' => 'cancelled', 'cancel_reason' => 'Сбой питания' ) );

		$card = $this->card();

		self::assertSame( 'event_cancelled', $card['state'] );
		self::assertSame( 'Проведение отменено', $card['state_label'] );
		self::assertSame( array(), $card['actions'] );
		self::assertSame( 'Сбой питания', $card['last_reason'] );
	}

	// ---- видимость проведений (5.2.1) ----------------------------------------------------------------------------------------------

	public function test_empty_list_when_student_has_no_events(): void {
		$this->catalog = array();

		self::assertSame( array( 'exams' => array() ), $this->service->build( self::PERSON ) );
	}

	public function test_student_sees_only_events_of_own_subjects(): void {
		$this->now     = '2026-03-02 09:00:00';
		$this->catalog = array( $this->event(), $this->event( array( 'id' => 2, 'subject_key' => 'math' ) ) );

		$exams = $this->service->build( self::PERSON )['exams'];

		self::assertSame( array( 1 ), array_column( $exams, 'event_id' ) );
		self::assertNotContains( 'draft', $this->requestedStatuses, 'Черновики у репозитория не запрашиваются.' );
		self::assertEqualsCanonicalizing( array( 'published', 'completed', 'cancelled' ), $this->requestedStatuses );
	}

	public function test_oge_student_does_not_see_ege_event(): void {
		$this->now         = '2026-03-02 09:00:00';
		$this->subjectKeys = array( 'inf_oge' );
		$this->catalog     = array( $this->event( array( 'id' => 1, 'subject_key' => 'inf_ege' ) ), $this->event( array( 'id' => 2, 'subject_key' => 'inf_oge' ) ) );

		self::assertSame( array( 2 ), array_column( $this->service->build( self::PERSON )['exams'], 'event_id' ) );
	}

	public function test_draft_event_is_hidden(): void {
		$this->withEvent( array( 'status' => 'draft' ) );
		$this->participationEventIds = array( 1 ); // даже через «историческое» участие черновик не показывается

		self::assertSame( array(), $this->service->build( self::PERSON )['exams'] );
	}

	public function test_event_with_existing_participation_stays_visible_after_leaving_group(): void {
		$this->subjectKeys           = array(); // ученик больше не состоит ни в одной группе предмета
		$this->participationEventIds = array( 1 );
		$this->arrangeRegistered();

		$exams = $this->service->build( self::PERSON )['exams'];

		self::assertSame( array( 1 ), array_column( $exams, 'event_id' ) );
		self::assertSame( 'entry_open', $exams[0]['state'] );
	}

	public function test_event_is_listed_once_when_visible_by_subject_and_by_participation(): void {
		$this->participationEventIds = array( 1 );
		$this->arrangeRegistered();

		self::assertCount( 1, $this->service->build( self::PERSON )['exams'] );
	}

	public function test_payload_contains_no_scores_or_answers(): void {
		$this->arrangeRegistered( 9 );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-13 10:00:00', 'graded' ) );
		$this->reviews->method( 'forStudent' )->willReturn( array(
			'revealed' => true,
			'result'   => array( 'primary' => 18, 'primary_max' => 29 ),
			'units'    => array( array( 'number' => '14', 'status' => 'correct', 'anchor' => 'u-abc', 'score' => 1.0, 'max' => 1.0, 'unit_key' => 'n:14', 'answer' => '42', 'solution' => 'x' ) ),
		) );

		$json = (string) wp_json_encode( $this->service->build( self::PERSON )['exams'] );

		foreach ( array( 'score', 'total_score', 'correct', 'tasks', 'answer', 'solution', 'unit_key' ) as $key ) {
			self::assertStringNotContainsString( '"' . $key . '":', $json, "Ключ {$key} не отдаётся карточке." );
		}
	}

	// ---- сеансы карточки (5.3.3) -----------------------------------------------------------------------------------------------------

	public function test_sessions_list_marks_full_sessions_not_selectable(): void {
		$this->now = '2026-03-02 09:00:00';
		$this->withSessions( array(
			$this->session( array( 'id' => 100, 'capacity' => 10, 'occupied_count' => 10 ) ),
			$this->session( array( 'id' => 101, 'capacity' => 8, 'occupied_count' => 5, 'scheduled_at' => '2026-03-13 07:00:00', 'planned_end_at' => '2026-03-13 10:55:00' ) ),
		) );

		$sessions = array_column( $this->card()['sessions'], null, 'session_id' );

		self::assertFalse( $sessions[100]['selectable'] );
		self::assertSame( 0, $sessions[100]['free'] );
		self::assertTrue( $sessions[101]['selectable'] );
		self::assertSame( 3, $sessions[101]['free'] );
		self::assertSame( 8, $sessions[101]['capacity'] );
	}

	public function test_sessions_exclude_cancelled_and_past(): void {
		$this->now = '2026-03-12 06:00:00';
		$this->withSessions( array(
			$this->session( array( 'id' => 100, 'status' => 'cancelled', 'scheduled_at' => '2026-03-13 07:00:00', 'planned_end_at' => '2026-03-13 10:55:00' ) ),
			$this->session( array( 'id' => 101, 'scheduled_at' => '2026-03-11 07:00:00', 'planned_end_at' => '2026-03-11 10:55:00' ) ),
			$this->session( array( 'id' => 102, 'scheduled_at' => '2026-03-14 07:00:00', 'planned_end_at' => '2026-03-14 10:55:00' ) ),
		) );

		self::assertSame( array( 102 ), array_column( $this->card()['sessions'], 'session_id' ) );
	}

	public function test_read_only_cards_have_no_actions_but_keep_state(): void {
		$this->now = '2026-03-02 09:00:00';

		$student = $this->card();
		$parent  = $this->card( true );

		self::assertSame( 'open', $student['state'] );
		self::assertSame( array( 'register' ), $student['actions'] );
		self::assertSame( 'open', $parent['state'], 'Состояние у родителя то же.' );
		self::assertSame( array(), $parent['actions'] );
	}

	// ---- расписание (5.5) ------------------------------------------------------------------------------------------------------------

	/** Четыре проведения ученика: нужное — только первое. */
	private function arrangeUpcomingScenario(): void {
		$this->now          = '2026-03-12 06:00:00';
		$this->catalog      = array(
			$this->event( array( 'id' => 1 ) ),
			$this->event( array( 'id' => 2 ) ),
			$this->event( array( 'id' => 3 ) ),
			$this->event( array( 'id' => 4, 'status' => 'cancelled' ) ),
		);
		$this->sessionList  = array(
			$this->session( array( 'id' => 101, 'event_id' => 1 ) ),
			$this->session( array( 'id' => 102, 'event_id' => 2 ) ),
			$this->session( array( 'id' => 103, 'event_id' => 3, 'scheduled_at' => '2026-03-10 07:00:00', 'planned_end_at' => '2026-03-10 10:55:00' ) ),
			$this->session( array( 'id' => 104, 'event_id' => 4 ) ),
		);
		$this->participationEventIds = array( 1, 2, 3, 4 );
		foreach ( array( 1, 2, 3, 4 ) as $eventId ) {
			$this->participationsByEvent[ $eventId ] = $this->participation( null, 4, array( 'id' => 70 + $eventId, 'event_id' => $eventId ) );
		}
		// Событие 2 — записи нет (отменена раньше); у остальных запись действует.
		foreach ( array( 1, 3, 4 ) as $eventId ) {
			$this->activeByParticipation[ 70 + $eventId ] = $this->registration( array( 'id' => 60 + $eventId, 'participation_id' => 70 + $eventId, 'session_id' => 100 + $eventId ) );
		}
	}

	public function test_upcoming_events_include_only_active_registrations(): void {
		$this->arrangeUpcomingScenario();

		$events = $this->service->upcomingEvents( self::PERSON );

		self::assertSame( array( 1 ), array_column( $events, 'event_id' ), 'Без записи, с прошедшим сеансом и отменённые проведения в расписание не попадают.' );
		self::assertSame( 'exam', $events[0]['kind'] );
		self::assertSame( 'Экзамен', $events[0]['group_name'] );
		self::assertSame( $events[0]['title'], $events[0]['topic'] );
		self::assertSame( 'registered', $events[0]['state'] );
		self::assertNull( $events[0]['deadline'], 'Пока попытки нет, дедлайна нет.' );
	}

	public function test_upcoming_events_are_sorted_by_date_and_time(): void {
		$this->arrangeUpcomingScenario();
		$this->activeByParticipation[72] = $this->registration( array( 'id' => 62, 'participation_id' => 72, 'session_id' => 102 ) );
		$this->sessionList[1]            = $this->session( array( 'id' => 102, 'event_id' => 2, 'scheduled_at' => '2026-03-12 06:30:00', 'planned_end_at' => '2026-03-12 10:25:00' ) );

		self::assertSame( array( 2, 1 ), array_column( $this->service->upcomingEvents( self::PERSON ), 'event_id' ) );
	}

	public function test_upcoming_events_are_local_time(): void {
		$GLOBALS['_fs_test_timezone'] = 'Europe/Moscow'; // UTC+3
		$this->now                    = '2026-03-12 23:00:00'; // местное; 20:00 UTC
		$this->arrangeUpcomingScenario();
		$this->now                    = '2026-03-12 23:00:00';
		$this->sessionList[0]         = $this->session( array( 'id' => 101, 'event_id' => 1, 'scheduled_at' => '2026-03-12 22:00:00', 'planned_end_at' => '2026-03-12 23:55:00' ) );

		$event = $this->service->upcomingEvents( self::PERSON )[0];

		self::assertSame( '2026-03-13', $event['date'], 'Сеанс в 22:00 UTC — это уже следующие сутки по Москве.' );
		self::assertSame( '01:00', $event['start'] );
		self::assertSame( '02:55', $event['end'] );
	}

	public function test_upcoming_event_carries_personal_deadline_of_running_attempt(): void {
		$this->arrangeUpcomingScenario();
		$this->participationsByEvent[1] = $this->participation( 9, 4, array( 'id' => 71, 'event_id' => 1 ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$event = $this->service->upcomingEvents( self::PERSON )[0];

		self::assertSame( 'in_progress', $event['state'] );
		self::assertSame( '11:05', $event['deadline'] );
	}

	// ---- ОГЭ и ЕГЭ: формат карточки (5.6.3) ------------------------------------------------------------------------------------------

	private function arrangeFormat( ExamFormatDTO $format ): void {
		$this->withEvent( array( 'default_assessment_id' => 50 ) );
		$this->assessments->method( 'get' )->willReturn( new AssessmentDTO(
			id: 50, subjectKey: 'inf', title: 'Вариант', taskIds: array(), timeLimit: 0, attemptsAllowed: 0, passScore: 0.0,
			scoringPolicy: ScoringPolicy::Highest, status: 'publish', kind: $format->kind, taskPoints: array(), scoreMap: array(),
		) );
		$this->formats->method( 'for' )->with( $format->kind )->willReturn( $format );
	}

	public function test_oge_card_has_grade_format_and_no_secondary_max(): void {
		$this->arrangeFormat( new ExamFormatDTO( AssessmentKind::OgeComputer, ExamDirection::Oge, 16, 21, null, 5, 150, array(), array() ) );

		$card = $this->card();

		self::assertSame( 'oge', $card['direction'] );
		self::assertSame(
			array( 'direction' => 'oge', 'unit_count' => 16, 'primary_max' => 21, 'secondary_max' => null, 'grade_max' => 5, 'duration_minutes' => 150 ),
			$card['format']
		);
	}

	public function test_ege_card_has_secondary_max_and_no_grade(): void {
		$this->arrangeFormat( new ExamFormatDTO( AssessmentKind::EgeComputer, ExamDirection::Ege, 27, 29, 100, 0, 235, array(), array() ) );

		$card = $this->card();

		self::assertSame( 'ege', $card['direction'] );
		self::assertSame( 100, $card['format']['secondary_max'] );
		self::assertSame( 0, $card['format']['grade_max'] );
		self::assertSame( 235, $card['format']['duration_minutes'] );
	}

	public function test_card_without_known_format_has_empty_direction_and_null_format(): void {
		$card = $this->card();

		self::assertSame( '', $card['direction'] );
		self::assertNull( $card['format'] );
	}
}
