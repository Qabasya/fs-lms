<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamOutboxEventDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Person\UserDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Profile\NotificationType;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Repositories\WPDBRepositories\RoomRepository;
use Inc\Services\Exam\ExamAudienceResolver;
use Inc\Services\Exam\ExamConductService;
use Inc\Services\Exam\ExamNotificationComposer;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamScoreService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Profile\NotificationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Композитор уведомлений: получатели по событиям, ключи дедупликации, гость, администраторы, снятие напоминаний.
 * Ученик — WP-пользователь 100, родитель — 200; ответственный за сеанс — 10; сайт — Москва (+3).
 */
#[AllowMockObjectsWithoutExpectations]
class ExamNotificationComposerTest extends TestCase {

	use ExamFixtures;

	private NotificationService&MockObject $notes;
	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamParticipationRepository&MockObject $participations;
	private ExamParticipantRepository&MockObject $participants;
	private ExamRegistrationRepository&MockObject $registrations;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ExamAudienceResolver&MockObject $audience;
	private ExamScoreService&MockObject $scores;
	private ExamOutbox&MockObject $outbox;
	private UserRepository&MockObject $users;
	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamPaymentLinkRepository&MockObject $links;
	private ExamSourceRepository&MockObject $sources;
	private ExamNotificationComposer $composer;

	/** @var list<array{users: array, type: NotificationType, key: string, payload: array, fresh: bool}> */
	private array $pushed = array();
	/** @var list<array{0: array, 1: string}> */
	private array $retracted = array();

	protected function setUp(): void {
		parent::setUp();

		$this->notes          = $this->createMock( NotificationService::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->audience       = $this->createMock( ExamAudienceResolver::class );
		$this->scores         = $this->createMock( ExamScoreService::class );
		$this->outbox         = $this->createMock( ExamOutbox::class );
		$this->users          = $this->createMock( UserRepository::class );
		$this->applications   = $this->createMock( ExamGuestApplicationRepository::class );
		$this->links          = $this->createMock( ExamPaymentLinkRepository::class );
		$this->sources        = $this->createMock( ExamSourceRepository::class );

		$conduct = $this->createMock( ExamConductService::class );
		$conduct->method( 'participantName' )->willReturn( 'Иванов Пётр' );
		$rooms = $this->createMock( RoomRepository::class );
		$rooms->method( 'find' )->willReturn( null );
		$time = $this->createMock( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-12 06:30:00' );
		$time->method( 'toLocal' )->willReturnCallback( static fn ( string $u ): string => gmdate( 'Y-m-d H:i:s', strtotime( $u . ' UTC' ) + 3 * 3600 ) );

		$this->notes->method( 'studentUserId' )->willReturn( 100 );
		$this->notes->method( 'guardianUserIds' )->willReturn( array( 200 ) );
		$this->notes->method( 'adminUserIds' )->willReturn( array( 301, 302 ) );
		$this->notes->method( 'push' )->willReturnCallback( function ( array $users, NotificationType $type, string $key, array $payload = array() ): void {
			$this->pushed[] = compact( 'users', 'type', 'key', 'payload' ) + array( 'fresh' => false );
		} );
		$this->notes->method( 'pushFresh' )->willReturnCallback( function ( array $users, NotificationType $type, string $key, array $payload = array() ): void {
			$this->pushed[] = compact( 'users', 'type', 'key', 'payload' ) + array( 'fresh' => true );
		} );
		$this->notes->method( 'retract' )->willReturnCallback( function ( array $users, string $key ): void {
			$this->retracted[] = array( $users, $key );
		} );

		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'owner_user_id' => '10' ) ) );
		$this->sessions->method( 'find' )->willReturn( $this->examSession() );
		$this->participations->method( 'find' )->willReturn( $this->participation() );
		$this->participants->method( 'find' )->willReturn( $this->participant( 1 ) );
		$this->registrations->method( 'find' )->willReturn( $this->registration() );

		$this->composer = new ExamNotificationComposer(
			$this->notes, $this->events, $this->sessions, $this->participations, $this->participants, $this->registrations, $this->attempts,
			$this->audience, $this->scores, $conduct, $rooms, $time, $this->outbox, $this->users, $this->applications, $this->links, $this->sources
		);
	}

	private function participation( string $audience = 'student' ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => 5, 'event_id' => 3, 'participant_id' => 4, 'audience' => $audience, 'transfer_allowed' => 0, 'version' => 2,
			'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	private function participant( ?int $personId ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => 4, 'person_id' => $personId, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
	}

	private function registration( int $id = 20 ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => 5, 'session_id' => 7, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	/** @param array<string, mixed> $payload */
	private function row( ExamOutboxEvent $type, string $aggregate, int $id, int $version, array $payload = array() ): ExamOutboxEventDTO {
		return ExamOutboxEventDTO::fromArray( array(
			'id' => 1, 'event_uuid' => 'u', 'type' => $type->value, 'aggregate_type' => $aggregate, 'aggregate_id' => $id, 'aggregate_version' => $version,
			'payload' => wp_json_encode( $payload ), 'available_at' => '2026-03-12 06:00:00', 'attempts' => 1, 'created_at' => '2026-03-12 06:00:00',
		) );
	}

	private function attempt( ?string $approvedAt = null ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 11, 'assessment_id' => 500, 'student_person_id' => 1, 'attempt_number' => 1, 'started_at' => '2026-03-10 10:00:00',
			'deadline_at' => '2026-03-10 13:55:00', 'status' => 'graded', 'approved_at' => $approvedAt, 'exam_participation_id' => 5, 'exam_registration_id' => 20, 'result_version' => 1,
		) );
	}

	private function lastPush(): array {
		self::assertNotEmpty( $this->pushed, 'Уведомление не создано.' );
		return end( $this->pushed );
	}

	// ── Таблица 9.2.2 ─────────────────────────────────────────────────────────────────────────────

	public function test_event_published_schedules_registration_opened_at_opens_at(): void {
		$this->events = $this->createMock( ExamEventRepository::class );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'registration_opens_at' => '2026-03-20 07:00:00' ) ) );
		$composer = $this->rebuild();
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::RegistrationOpened, 'event', 3, 4, array( 'event_id' => 3 ), '2026-03-20 07:00:00' );

		$composer->handle( $this->row( ExamOutboxEvent::EventPublished, 'event', 3, 4 ) );
	}

	public function test_registration_opened_is_delayed_until_opens_at_or_immediate_when_open(): void {
		$this->events = $this->createMock( ExamEventRepository::class );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'registration_opens_at' => '2026-03-01 07:00:00' ) ) );
		$composer = $this->rebuild();
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::RegistrationOpened, 'event', 3, 4, self::anything(), null );

		$composer->handle( $this->row( ExamOutboxEvent::EventPublished, 'event', 3, 4 ) );
	}

	public function test_registration_opened_goes_to_audience_and_guardians_once_per_event(): void {
		$this->audience->method( 'studentPersonIds' )->willReturn( array( 1, 2 ) );

		$this->composer->handle( $this->row( ExamOutboxEvent::RegistrationOpened, 'event', 3, 4 ) );

		$push = $this->lastPush();
		self::assertSame( NotificationType::ExamRegistrationOpened, $push['type'] );
		self::assertSame( array( 100, 200 ), $push['users'] );
		self::assertSame( 'exam:opened:3', $push['key'] );
	}

	public function test_registration_confirmed_goes_to_student_and_guardians(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::RegistrationConfirmed, 'registration', 20, 1, array( 'event_id' => 3, 'session_id' => 7, 'participation_id' => 5 ) ) );

		$push = $this->lastPush();
		self::assertSame( NotificationType::ExamRegistrationConfirmed, $push['type'] );
		self::assertSame( array( 100, 200 ), $push['users'] );
		self::assertSame( '12.03.2026', gmdate( 'd.m.Y', strtotime( '2026-03-12' ) ) );
		self::assertSame( 'Пробный ЕГЭ', $push['payload']['event_title'] );
		self::assertSame( '10:00', $push['payload']['time'] );
	}

	public function test_transfer_retracts_old_reminders(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::RegistrationTransferred, 'registration', 21, 1, array( 'old_registration_id' => 20, 'old_session_id' => 7, 'new_session_id' => 7, 'by' => 'self' ) ) );

		self::assertSame( NotificationType::ExamRegistrationChanged, $this->lastPush()['type'] );
		self::assertContains( array( array( 100, 200 ), 'exam:soon:20' ), $this->retracted );
		self::assertContains( array( array( 100, 200 ), 'exam:tomorrow:20' ), $this->retracted );
		self::assertContains( array( array( 100, 200 ), 'exam:entry:20' ), $this->retracted );
	}

	public function test_cancel_retracts_reminders(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::RegistrationCancelled, 'registration', 20, 1, array( 'session_id' => 7, 'by' => 'self' ) ) );

		self::assertContains( array( array( 100, 200 ), 'exam:soon:20' ), $this->retracted );
		self::assertSame( NotificationType::ExamRegistrationCancelled, $this->lastPush()['type'] );
	}

	public function test_self_cancel_has_no_reason_and_staff_cancel_has_one(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::RegistrationCancelled, 'registration', 20, 1, array( 'session_id' => 7, 'by' => 'self', 'reason' => 'x' ) ) );
		self::assertArrayNotHasKey( 'reason', $this->lastPush()['payload'] );

		$this->composer->handle( $this->row( ExamOutboxEvent::RegistrationCancelled, 'registration', 20, 1, array( 'session_id' => 7, 'by' => 'staff', 'reason' => 'Авария' ) ) );
		self::assertSame( 'Авария', $this->lastPush()['payload']['reason'] );
	}

	public function test_cancel_of_cancelled_session_is_reported_as_session_cancelled(): void {
		$this->sessions = $this->createMock( ExamSessionRepository::class );
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'status' => 'cancelled' ) ) );

		$this->rebuild()->handle( $this->row( ExamOutboxEvent::RegistrationCancelled, 'registration', 20, 1, array( 'session_id' => 7, 'by' => 'staff', 'reason' => 'Авария' ) ) );

		self::assertSame( NotificationType::ExamSessionCancelled, $this->lastPush()['type'] );
	}

	public function test_participant_missed_goes_to_student_and_retracts(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::ParticipantMissed, 'participation', 5, 2, array( 'registration_id' => 20, 'session_id' => 7 ) ) );

		self::assertSame( NotificationType::ExamMissed, $this->lastPush()['type'] );
		self::assertContains( array( array( 100, 200 ), 'exam:entry:20' ), $this->retracted );
	}

	public function test_attempt_submitted_notifies_student_and_responsible(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptSubmitted, 'participation', 5, 2, array( 'attempt_id' => 11 ) ) );

		self::assertSame( NotificationType::ExamWorkAccepted, $this->pushed[0]['type'] );
		self::assertSame( array( 100, 200 ), $this->pushed[0]['users'] );
		self::assertSame( NotificationType::ExamWorkSubmitted, $this->pushed[1]['type'] );
		self::assertSame( array( 10 ), $this->pushed[1]['users'] );
		self::assertSame( 'Иванов Пётр', $this->pushed[1]['payload']['participant_name'] );
	}

	public function test_work_accepted_has_no_scores(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptSubmitted, 'participation', 5, 2, array( 'attempt_id' => 11 ) ) );

		self::assertSame( array( 'event_title' ), array_keys( $this->pushed[0]['payload'] ) );
	}

	public function test_guest_participation_has_no_student_recipients(): void {
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participations->method( 'find' )->willReturn( $this->participation( 'guest' ) );
		$this->participants = $this->createMock( ExamParticipantRepository::class );
		$this->participants->method( 'find' )->willReturn( $this->participant( null ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt() );

		$this->rebuild()->handle( $this->row( ExamOutboxEvent::AttemptSubmitted, 'participation', 5, 2, array( 'attempt_id' => 11 ) ) );

		self::assertSame( array(), $this->pushed[0]['users'], 'Гостю уведомление не отправляется.' );
		self::assertSame( array( 10 ), $this->pushed[1]['users'], 'Ответственный о сдаче гостя узнаёт.' );
		self::assertSame( 'Иванов Пётр (гость)', $this->pushed[1]['payload']['participant_name'] );
	}

	public function test_dedupe_key_is_stable_for_same_outbox_row(): void {
		$row = $this->row( ExamOutboxEvent::RegistrationConfirmed, 'registration', 20, 1, array( 'session_id' => 7, 'participation_id' => 5 ) );

		$this->composer->handle( $row );
		$this->composer->handle( $row );

		self::assertSame( $this->pushed[0]['key'], $this->pushed[1]['key'] );
		self::assertSame( 'exam:registration_confirmed:20:1', $this->pushed[0]['key'] );
		self::assertLessThanOrEqual( 120, strlen( $this->pushed[0]['key'] ) );
	}

	public function test_approved_notification_contains_score_caption(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-10 15:00:00' ) );
		$this->scores->method( 'summarize' )->willReturn( array( 'direction' => 'ege', 'primary' => 20, 'primary_max' => 29, 'secondary' => 84, 'secondary_max' => 100, 'grade' => null, 'final' => true ) );

		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptApproved, 'participation', 5, 2, array( 'attempt_id' => 11, 'participation_id' => 5 ) ) );

		$push = $this->lastPush();
		self::assertSame( '84 из 100', $push['payload']['score_caption'] );
		self::assertSame( 'exam:approved:11', $push['key'] );
	}

	public function test_oge_caption_has_grade(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-10 15:00:00' ) );
		$this->scores->method( 'summarize' )->willReturn( array( 'direction' => 'oge', 'primary' => 15, 'primary_max' => 19, 'secondary' => null, 'grade' => 4, 'final' => true ) );

		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptApproved, 'participation', 5, 2, array( 'attempt_id' => 11, 'participation_id' => 5 ) ) );

		self::assertSame( '15 из 19, отметка 4', $this->lastPush()['payload']['score_caption'] );
	}

	public function test_two_approved_events_for_same_attempt_give_one_notification(): void {
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-10 15:00:00' ) );
		$this->scores->method( 'summarize' )->willReturn( array( 'direction' => 'ege', 'primary' => 1, 'primary_max' => 2, 'secondary' => null, 'grade' => null, 'final' => true ) );

		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptApproved, 'participation', 5, 2, array( 'attempt_id' => 11 ) ) );
		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptApproved, 'participation', 5, 3, array( 'attempt_id' => 11 ) ) );

		// Ключ один на попытку: вставку второго отбрасывает уникальный индекс «получатель + ключ».
		self::assertCount( 1, array_unique( array_column( $this->pushed, 'key' ) ) );
	}

	public function test_corrected_replaces_previous_unread_and_contains_reason(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::ResultCorrected, 'participation', 5, 2, array( 'attempt_id' => 11, 'participation_id' => 5, 'reason' => 'Пересчёт' ) ) );

		$push = $this->lastPush();
		self::assertTrue( $push['fresh'] );
		self::assertSame( 'exam:corrected:11', $push['key'] );
		self::assertSame( 'Пересчёт', $push['payload']['reason'] );
	}

	public function test_guest_gets_neither_approved_nor_corrected(): void {
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participations->method( 'find' )->willReturn( $this->participation( 'guest' ) );
		$this->participants = $this->createMock( ExamParticipantRepository::class );
		$this->participants->method( 'find' )->willReturn( $this->participant( null ) );
		$this->attempts->method( 'find' )->willReturn( $this->attempt( '2026-03-10 15:00:00' ) );
		$this->scores->method( 'summarize' )->willReturn( array( 'direction' => 'ege', 'primary' => 1, 'primary_max' => 2, 'secondary' => null, 'grade' => null, 'final' => true ) );
		$composer = $this->rebuild();

		$composer->handle( $this->row( ExamOutboxEvent::AttemptApproved, 'participation', 5, 2, array( 'attempt_id' => 11 ) ) );
		$composer->handle( $this->row( ExamOutboxEvent::ResultCorrected, 'participation', 5, 2, array( 'attempt_id' => 11, 'reason' => 'x' ) ) );

		foreach ( $this->pushed as $push ) {
			self::assertSame( array(), $push['users'] );
		}
	}

	public function test_extended_key_depends_on_new_deadline(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptExtended, 'participation', 5, 2, array( 'attempt_id' => 11, 'reason' => 'Сбой', 'deadline_at' => '2026-03-10 14:05:00' ) ) );
		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptExtended, 'participation', 5, 2, array( 'attempt_id' => 11, 'reason' => 'Сбой', 'deadline_at' => '2026-03-10 14:15:00' ) ) );

		self::assertNotSame( $this->pushed[0]['key'], $this->pushed[1]['key'] );
		self::assertSame( '14:05', $this->pushed[0]['payload']['time_end'] );
	}

	public function test_session_moved_goes_to_registered_and_retracts_reminders(): void {
		$this->registrations = $this->createMock( ExamRegistrationRepository::class );
		$this->registrations->method( 'listBySession' )->willReturn( array( $this->registration() ) );

		$this->rebuild()->handle( $this->row( ExamOutboxEvent::SessionMoved, 'session', 7, 3, array( 'session_id' => 7, 'reason' => 'Перенос' ) ) );

		self::assertSame( NotificationType::ExamSessionMoved, $this->lastPush()['type'] );
		self::assertSame( 'Перенос', $this->lastPush()['payload']['reason'] );
		self::assertContains( array( array( 100, 200 ), 'exam:soon:20' ), $this->retracted );
	}

	public function test_session_cancelled_only_retracts_reminders(): void {
		$this->registrations = $this->createMock( ExamRegistrationRepository::class );
		$this->registrations->method( 'listBySession' )->willReturn( array( $this->registration() ) );

		$this->rebuild()->handle( $this->row( ExamOutboxEvent::SessionCancelled, 'session', 7, 3, array( 'session_id' => 7 ) ) );

		self::assertSame( array(), $this->pushed, 'Сообщение участнику приходит от отмены его записи с той же причиной.' );
		self::assertNotEmpty( $this->retracted );
	}

	// ── Администраторы платформы (9.5) ────────────────────────────────────────────────────────────

	private function problemApplication(): void {
		$this->applications->method( 'find' )->willReturn( ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'paid_needs_resolution',
			'is_held' => '0', 'request_key' => 'k', 'version' => '4', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );
	}

	public function test_needs_help_goes_to_every_office_user_once(): void {
		$this->problemApplication();

		$this->composer->handle( $this->row( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', 9, 4, array( 'application_id' => 9 ) ) );
		$this->composer->handle( $this->row( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', 9, 4, array( 'application_id' => 9 ) ) );

		self::assertSame( array( 301, 302 ), $this->pushed[0]['users'] );
		self::assertSame( NotificationType::ExamPaymentNeedsHelp, $this->pushed[0]['type'] );
		self::assertSame( $this->pushed[0]['key'], $this->pushed[1]['key'] );
		self::assertSame( 'exam:needs_help:9:4', $this->pushed[0]['key'] );
	}

	public function test_needs_help_falls_back_to_admins_when_no_office_users(): void {
		$this->problemApplication();
		$notes = $this->createMock( NotificationService::class );
		$notes->method( 'adminUserIds' )->willReturn( array() );
		$notes->method( 'push' )->willReturnCallback( function ( array $users, NotificationType $type, string $key ): void {
			$this->pushed[] = compact( 'users', 'type', 'key' ) + array( 'payload' => array(), 'fresh' => false );
		} );
		$this->notes = $notes;
		$this->users->method( 'getByCapability' )->willReturn( array( $this->user( 1 ) ) );

		$this->rebuild()->handle( $this->row( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', 9, 4, array( 'application_id' => 9 ) ) );

		self::assertSame( array( 1 ), $this->pushed[0]['users'] );
	}

	public function test_same_application_version_does_not_repeat(): void {
		$this->problemApplication();

		$this->composer->handle( $this->row( ExamOutboxEvent::ReconcileFailed, 'guest_application', 9, 4, array( 'application_id' => 9 ) ) );
		$this->composer->handle( $this->row( ExamOutboxEvent::ReconcileFailed, 'guest_application', 9, 5, array( 'application_id' => 9 ) ) );

		self::assertSame( 'exam:reconcile:9:4', $this->pushed[0]['key'] );
		self::assertSame( 'exam:reconcile:9:5', $this->pushed[1]['key'] );
	}

	public function test_source_limit_goes_to_responsible_and_office_once_per_hour(): void {
		$this->sources->method( 'find' )->willReturn( ExamSourceDTO::fromArray( array(
			'id' => '14', 'event_id' => '3', 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5', 'grade' => '11', 'teacher_name' => 'Т', 'label' => 'l',
			'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );

		$this->composer->handle( $this->row( ExamOutboxEvent::SourceLimitExceeded, 'source', 14, 1, array( 'event_id' => 3, 'source_id' => 14 ) ) );
		$this->composer->handle( $this->row( ExamOutboxEvent::SourceLimitExceeded, 'source', 14, 1, array( 'event_id' => 3, 'source_id' => 14 ) ) );

		self::assertSame( array( 10, 301, 302 ), $this->pushed[0]['users'] );
		self::assertSame( $this->pushed[0]['key'], $this->pushed[1]['key'] );
		self::assertMatchesRegularExpression( '/^exam:source_limit:14:\d{10}$/', $this->pushed[0]['key'] );
	}

	public function test_payload_has_no_phone(): void {
		$this->problemApplication();

		$this->composer->handle( $this->row( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', 9, 4, array( 'application_id' => 9 ) ) );

		foreach ( array_keys( $this->pushed[0]['payload'] ) as $key ) {
			self::assertDoesNotMatchRegularExpression( '/phone|tel|messenger|contact/i', (string) $key );
		}
	}

	public function test_events_without_notifications_are_ignored(): void {
		$this->composer->handle( $this->row( ExamOutboxEvent::AttemptStarted, 'participation', 5, 2, array( 'attempt_id' => 11 ) ) );

		self::assertSame( array(), $this->pushed );
	}

	public function test_remind_pushes_with_registration_key_and_skips_guest(): void {
		self::assertTrue( $this->composer->remind( NotificationType::ExamSoon, 'soon', $this->registration() ) );
		self::assertSame( 'exam:soon:20', $this->lastPush()['key'] );

		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->participations->method( 'find' )->willReturn( $this->participation( 'guest' ) );
		self::assertFalse( $this->rebuild()->remind( NotificationType::ExamSoon, 'soon', $this->registration() ) );
	}

	private function user( int $id ): UserDTO {
		return new UserDTO( $id, 'a@example.test', 'Админ', UserRole::FSOffice );
	}

	/** Пересобирает композитор с подменёнными зависимостями (свойства теста). */
	private function rebuild(): ExamNotificationComposer {
		$conduct = $this->createMock( ExamConductService::class );
		$conduct->method( 'participantName' )->willReturn( 'Иванов Пётр' );
		$rooms = $this->createMock( RoomRepository::class );
		$time  = $this->createMock( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-12 06:30:00' );
		$time->method( 'toLocal' )->willReturnCallback( static fn ( string $u ): string => gmdate( 'Y-m-d H:i:s', strtotime( $u . ' UTC' ) + 3 * 3600 ) );

		return new ExamNotificationComposer(
			$this->notes, $this->events, $this->sessions, $this->participations, $this->participants, $this->registrations, $this->attempts,
			$this->audience, $this->scores, $conduct, $rooms, $time, $this->outbox, $this->users, $this->applications, $this->links, $this->sources
		);
	}
}
