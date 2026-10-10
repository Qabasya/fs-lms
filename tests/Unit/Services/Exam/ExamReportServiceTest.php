<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\ConsentDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ConsentRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamReportMemberRepository;
use Inc\Repositories\WPDBRepositories\ExamReportRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamReportService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Школьные отчёты: кого можно включить (таблица 12.2.4), срок, версия состава, ключ.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamReportServiceTest extends TestCase {

	use ExamFixtures;

	private const ACTOR = 10;
	private const EVENT = 3;

	private ExamReportRepository&MockObject $reports;
	private ExamReportMemberRepository&MockObject $members;
	private ExamParticipationRepository&MockObject $participations;
	private ExamEventRepository&MockObject $events;
	private ExamSourceRepository&MockObject $sources;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ConsentRepository&MockObject $consents;
	private ExamAccessGuard&MockObject $guard;
	private ExamAccessTokenService&MockObject $tokens;
	private LogEventDispatcherInterface&MockObject $log;
	private PluginConfig&MockObject $config;
	private ExamReportService $service;

	/** @var array<int, ExamParticipationDTO> */
	private array $byId = array();
	/** @var array<string, mixed> */
	private array $inserted = array();
	private ?AttemptDTO $attempt = null;
	private ?ConsentDTO $consent = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_test_user_can'][ self::ACTOR ]['share_lms_exam_results'] = true;

		$this->reports        = $this->createMock( ExamReportRepository::class );
		$this->members        = $this->createMock( ExamReportMemberRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sources        = $this->createMock( ExamSourceRepository::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->consents       = $this->createMock( ConsentRepository::class );
		$this->guard          = $this->createMock( ExamAccessGuard::class );
		$this->tokens         = $this->createMock( ExamAccessTokenService::class );
		$this->log            = $this->createMock( LogEventDispatcherInterface::class );
		$this->config         = $this->createMock( PluginConfig::class );
		$time                 = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'toLocal' )->willReturnArgument( 0 );
		$time->method( 'addMinutes' )->willReturnCallback( static fn ( string $d, int $m ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' UTC' ) + $m * 60 ) );
		$time->method( 'endOfLocalDayUtc' )->willReturnCallback( static fn ( string $d ): string => $d . ' 23:59:59' );

		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'period_to' => '2026-03-12' ) ) );
		$this->guard->method( 'canManageEvent' )->willReturn( true );
		$this->config->method( 'examGuestRetentionDays' )->willReturn( 30 );
		$this->participations->method( 'find' )->willReturnCallback( fn ( int $id ): ?ExamParticipationDTO => $this->byId[ $id ] ?? null );
		$this->attempts->method( 'find' )->willReturnCallback( fn (): ?AttemptDTO => $this->attempt );
		$this->consents->method( 'find' )->willReturnCallback( fn (): ?ConsentDTO => $this->consent );
		$this->reports->method( 'insert' )->willReturnCallback( function ( array $row ): int {
			$this->inserted = $row;
			return 7;
		} );
		$this->reports->method( 'find' )->willReturnCallback( fn (): ExamReportDTO => $this->report() );

		$this->service = new ExamReportService(
			$this->reports, $this->members, $this->participations, $this->events, $this->sources, $this->attempts, $this->consents,
			$this->guard, $this->tokens, $this->log, $this->config, $time
		);
		$this->attempt = $this->attempt( 'submitted', true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_test_user_can'] );
		parent::tearDown();
	}

	/** @param array<string, mixed> $over */
	private function participation( int $id, array $over = array() ): ExamParticipationDTO {
		$p = ExamParticipationDTO::fromArray( array_merge( array(
			'id' => $id, 'event_id' => self::EVENT, 'participant_id' => $id, 'audience' => 'student', 'current_attempt_id' => 55, 'transfer_allowed' => 0,
			'consent_refs' => null, 'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		), $over ) );
		$this->byId[ $id ] = $p;

		return $p;
	}

	private function attempt( string $status, bool $approved ): AttemptDTO {
		return AttemptDTO::fromArray( array(
			'id' => 55, 'assessment_id' => 5, 'student_person_id' => null, 'group_id' => null, 'attempt_number' => 1, 'status' => $status,
			'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 14:00:00', 'approved_at' => $approved ? '2026-03-10 15:00:00' : null,
		) );
	}

	private function guest( int $id, bool $transfer = true, ?string $withdrawnAt = null ): ExamParticipationDTO {
		$this->consent = $this->consentDto( $withdrawnAt );

		return $this->participation( $id, array( 'audience' => 'guest', 'transfer_allowed' => $transfer ? 1 : 0, 'consent_refs' => '{"pd_processing":11,"pd_transfer":12}' ) );
	}

	private function consentDto( ?string $withdrawnAt ): ConsentDTO {
		return $this->makeConsent( $withdrawnAt );
	}

	private function makeConsent( ?string $withdrawnAt ): ConsentDTO {
		$ref  = new \ReflectionClass( ConsentDTO::class );
		$dto  = $ref->newInstanceWithoutConstructor();
		$prop = $ref->getProperty( 'withdrawnAt' );
		$prop->setValue( $dto, $withdrawnAt );

		return $dto;
	}

	private function report( array $over = array() ): ExamReportDTO {
		return ExamReportDTO::fromArray( array_merge( array(
			'id' => 7, 'event_id' => self::EVENT, 'title' => 'Школа 5', 'owner_user_id' => self::ACTOR, 'recipient_source_id' => null,
			'expires_at' => '2026-06-08 07:00:00', 'revoked_at' => null, 'version' => 1, 'created_at' => '2026-03-10 07:00:00',
		), $over ) );
	}

	private function refusal( callable $call ): CodedException {
		try {
			$call();
		} catch ( CodedException $e ) {
			return $e;
		}
		self::fail( 'Ожидался отказ.' );
	}

	public function test_create_requires_share_cap_and_event_scope(): void {
		$GLOBALS['_test_user_can'][ self::ACTOR ]['share_lms_exam_results'] = false;
		self::assertSame( ErrorCode::ExamAccess, $this->refusal( fn () => $this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, null ) )->errorCode );

		$GLOBALS['_test_user_can'][ self::ACTOR ]['share_lms_exam_results'] = true;
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEvent' )->willReturn( false );
		$svc = new ExamReportService( $this->reports, $this->members, $this->participations, $this->events, $this->sources, $this->attempts, $this->consents, $guard, $this->tokens, $this->log, $this->config, $this->createStub( ExamTime::class ) );
		self::assertSame( ErrorCode::ExamAccess, $this->refusal( fn () => $svc->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, null ) )->errorCode );
	}

	public function test_cannot_include_participant_of_another_event(): void {
		self::assertSame( 'Участник не из этого проведения.', $this->service->canInclude( $this->participation( 1, array( 'event_id' => 99 ) ), self::EVENT ) );
	}

	public function test_cannot_include_unsubmitted_attempt(): void {
		$this->attempt = $this->attempt( 'in_progress', false );
		self::assertSame( 'Работа не сдана.', $this->service->canInclude( $this->participation( 1 ), self::EVENT ) );

		self::assertSame( 'Работа не сдана.', $this->service->canInclude( $this->participation( 2, array( 'current_attempt_id' => null ) ), self::EVENT ) );
	}

	public function test_cannot_include_unapproved_student(): void {
		$this->attempt = $this->attempt( 'graded', false );
		self::assertSame( 'Результат ученика не утверждён.', $this->service->canInclude( $this->participation( 1 ), self::EVENT ) );
	}

	public function test_guest_without_transfer_consent_is_rejected(): void {
		self::assertSame( 'Нет согласия на передачу результата школе.', $this->service->canInclude( $this->guest( 1, false ), self::EVENT ) );

		$e = $this->refusal( fn () => $this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, null ) );
		self::assertStringContainsString( 'Нет согласия на передачу результата школе.', $e->getMessage() );
		self::assertSame( array(), $this->inserted, 'Отказ — всей операции: отчёт не создан.' );
	}

	public function test_cannot_include_guest_with_withdrawn_consent(): void {
		self::assertSame( 'Согласие отозвано.', $this->service->canInclude( $this->guest( 1, true, '2026-03-11 00:00:00' ), self::EVENT ) );
	}

	public function test_center_student_included_without_guest_consent(): void {
		$this->attempt = $this->attempt( 'graded', true );

		self::assertNull( $this->service->canInclude( $this->participation( 1 ), self::EVENT ) );
	}

	public function test_payment_or_invitation_is_not_treated_as_consent(): void {
		// Участие с источником (приглашение) и подтверждённой записью, но без согласия на передачу: отказ.
		$p = $this->participation( 1, array( 'audience' => 'guest', 'source_id' => 14, 'active_registration_id' => 80, 'transfer_allowed' => 0 ) );

		self::assertSame( 'Нет согласия на передачу результата школе.', $this->service->canInclude( $p, self::EVENT ) );
	}

	public function test_default_term_is_ninety_days_and_capped_by_retention(): void {
		$this->guest( 1 );
		$this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, null );

		// Проведение закончилось 12 марта, хранение 30 дней → 11 апреля; 90 дней от 10 марта — 8 июня: берётся меньшее.
		self::assertSame( '2026-04-11 23:59:59', $this->inserted['expires_at'] );
		self::assertSame( 1, $this->inserted['version'] );
	}

	public function test_term_outside_range_is_refused(): void {
		$this->guest( 1 );
		self::assertSame( ErrorCode::ExamConflict, $this->refusal( fn () => $this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, 91 ) )->errorCode );
		self::assertSame( ErrorCode::ExamConflict, $this->refusal( fn () => $this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, 0 ) )->errorCode );
	}

	public function test_new_guest_of_same_school_is_not_added_automatically(): void {
		$this->guest( 1 );
		$this->members->expects( self::once() )->method( 'add' )->with( 7, 1, 12, self::isType( 'string' ) );

		// Состав — ровно выбранный: других участий (даже той же школы) сервис не читает и не добавляет.
		$this->participations->expects( self::never() )->method( 'findByEvent' );
		$this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), null, null );
	}

	public function test_recipient_source_must_belong_to_event(): void {
		$this->guest( 1 );
		$this->sources->method( 'find' )->willReturn( \Inc\DTO\Exam\ExamSourceDTO::fromArray( array(
			'id' => '14', 'event_id' => '99', 'school_name' => 'X', 'school_name_normalized' => 'x', 'grade' => '11', 'teacher_name' => 'Т', 'label' => 'l',
			'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );

		self::assertSame( ErrorCode::ExamConflict, $this->refusal( fn () => $this->service->create( self::ACTOR, self::EVENT, 'Отчёт', array( 1 ), 14, null ) )->errorCode );
	}

	public function test_member_change_is_logged_and_versioned(): void {
		$this->guest( 1 );
		$this->reports->expects( self::once() )->method( 'bumpVersion' )->with( 7, 3 )->willReturn( true );
		$this->members->expects( self::once() )->method( 'add' )->willReturn( true );
		$this->log->expects( self::once() )->method( 'dispatch' )->with( LogEvent::ExamReportChanged, self::anything() );

		$this->service->addMember( self::ACTOR, 7, 1, 3 );
	}

	public function test_stale_member_change_is_refused(): void {
		$this->guest( 1 );
		$this->reports->method( 'bumpVersion' )->willReturn( false );
		$this->members->expects( self::never() )->method( 'add' );

		self::assertSame( ErrorCode::ExamStale, $this->refusal( fn () => $this->service->addMember( self::ACTOR, 7, 1, 1 ) )->errorCode );
	}

	public function test_revoke_blocks_key_exchange(): void {
		$this->reports->expects( self::once() )->method( 'setRevoked' )->willReturn( true );
		$this->tokens->expects( self::once() )->method( 'revoke' )->with( ExamTokenPurpose::Report, 7 );

		$this->service->revoke( self::ACTOR, 7, 1 );
	}

	public function test_reissue_revokes_previous_key(): void {
		// issue() отзывает прежние ключи цели; срок ключа — срок отчёта.
		$this->tokens->expects( self::once() )->method( 'issue' )->with( ExamTokenPurpose::Report, 7, self::ACTOR, '2026-06-08 07:00:00' )->willReturn( str_repeat( 'a', 64 ) );

		self::assertStringContainsString( '?k=' . str_repeat( 'a', 64 ), $this->service->issueLink( self::ACTOR, 7 ) );
	}

	public function test_revoked_report_cannot_issue_link_or_change_members(): void {
		$revoked = $this->createMock( ExamReportRepository::class );
		$revoked->method( 'find' )->willReturn( $this->report( array( 'revoked_at' => '2026-03-10 08:00:00' ) ) );
		$svc = new ExamReportService( $revoked, $this->members, $this->participations, $this->events, $this->sources, $this->attempts, $this->consents, $this->guard, $this->tokens, $this->log, $this->config, $this->createStub( ExamTime::class ) );
		$this->tokens->expects( self::never() )->method( 'issue' );

		self::assertSame( ErrorCode::ExamClosed, $this->refusal( fn () => $svc->issueLink( self::ACTOR, 7 ) )->errorCode );
	}

	public function test_report_shows_current_result_after_correction(): void {
		// Отчёт хранит только состав (участия), а не баллы: итог читается из попытки при каждом построении страницы.
		$params = array_map( static fn ( \ReflectionParameter $p ): string => $p->getName(), ( new \ReflectionClass( \Inc\DTO\Exam\ExamReportMemberDTO::class ) )->getConstructor()->getParameters() );

		self::assertSame( array( 'id', 'reportId', 'participationId', 'consentRef', 'createdAt' ), $params );
	}
}
