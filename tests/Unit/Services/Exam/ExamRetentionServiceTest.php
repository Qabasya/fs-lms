<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\Contracts\LogEventDispatcherInterface;
use Inc\DTO\Assessment\AttemptAnswerDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\ErrorCode;
use Inc\Enums\Log\LogEvent;
use Inc\Managers\Wp\MediaManager;
use Inc\Repositories\WPDBRepositories\AssessmentAnswerRepository;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamAccessTokenRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamOutboxEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamRetentionService;
use Inc\Services\Exam\ExamTickLock;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Хранение данных гостей: обезличивание по сроку и по запросу, неоплаченные заявки, чего не трогаем.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamRetentionServiceTest extends TestCase {

	use ExamFixtures;

	private ExamParticipantRepository&MockObject $participants;
	private ExamParticipationRepository&MockObject $participations;
	private ExamGuestApplicationRepository&MockObject $applications;
	private AssessmentAnswerRepository&MockObject $answers;
	private ExamAccessTokenService&MockObject $tokens;
	private ExamAccessTokenRepository&MockObject $tokenRows;
	private ExamGuestSessionRepository&MockObject $sessionRows;
	private GuestSessionService&MockObject $sessions;
	private ExamOutboxEventRepository&MockObject $outbox;
	private MediaManager&MockObject $media;
	private LogEventDispatcherInterface&MockObject $log;
	private ExamEventRepository&MockObject $events;
	private ExamAccessGuard&MockObject $guard;
	private ExamRetentionService $service;
	private \wpdb $originalWpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->originalWpdb = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']    = new class() extends \wpdb {
			public function query( string $sql ): bool|int {
				return 1;
			}
		};

		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->applications   = $this->createMock( ExamGuestApplicationRepository::class );
		$this->answers        = $this->createMock( AssessmentAnswerRepository::class );
		$this->tokens         = $this->createMock( ExamAccessTokenService::class );
		$this->tokenRows      = $this->createMock( ExamAccessTokenRepository::class );
		$this->sessionRows    = $this->createMock( ExamGuestSessionRepository::class );
		$this->sessions       = $this->createMock( GuestSessionService::class );
		$this->outbox         = $this->createMock( ExamOutboxEventRepository::class );
		$this->media          = $this->createMock( MediaManager::class );
		$this->log            = $this->createMock( LogEventDispatcherInterface::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->guard          = $this->createMock( ExamAccessGuard::class );
		$config               = $this->createMock( PluginConfig::class );
		$config->method( 'examGuestRetentionDays' )->willReturn( 365 );
		$config->method( 'examUnpaidRetentionDays' )->willReturn( 30 );
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'addMinutes' )->willReturnCallback( static fn ( string $d, int $m ): string => gmdate( 'Y-m-d H:i:s', strtotime( $d . ' UTC' ) + $m * 60 ) );

		$this->service = new ExamRetentionService(
			$this->participants, $this->participations, $this->applications, $this->createMock( AssessmentAttemptRepository::class ), $this->answers,
			$this->tokens, $this->tokenRows, $this->sessionRows, $this->sessions, $this->outbox, $this->media, $config, $this->log,
			new ExamTickLock( $this->createMock( \Inc\Repositories\WPDBRepositories\ExamLockRepository::class ) ), $time, $this->events, $this->guard
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->originalWpdb;
		parent::tearDown();
	}

	private function participant( ?int $personId = null, ?string $anonymizedAt = null ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => 9, 'person_id' => $personId, 'anonymized_at' => $anonymizedAt, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00' ) );
	}

	private function participation( int $id, ?int $attemptId = 55 ): ExamParticipationDTO {
		return ExamParticipationDTO::fromArray( array(
			'id' => $id, 'event_id' => 3, 'participant_id' => 9, 'audience' => 'guest', 'current_attempt_id' => $attemptId, 'transfer_allowed' => 0,
			'version' => 1, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
		) );
	}

	public function test_guest_anonymized_after_retention_period(): void {
		// Срок — 365 дней: граница передаётся запросу как «сейчас − 365 дней».
		$this->participants->expects( self::once() )->method( 'listGuestIdsDueForAnonymization' )->with( '2025-03-10 07:00:00', 200 )->willReturn( array( 9 ) );
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participations->method( 'findByParticipant' )->willReturn( array( $this->participation( 80 ) ) );
		$this->participants->expects( self::once() )->method( 'anonymize' )->with( 9, '2026-03-10 07:00:00' )->willReturn( true );
		$this->log->expects( self::once() )->method( 'dispatch' )->with( LogEvent::ExamGuestAnonymized, self::anything() );

		self::assertSame( 1, $this->service->sweepGuests() );
	}

	public function test_guest_kept_before_period(): void {
		// Запрос вернул пусто: проведения не старше срока, участник в выборку не попал, ничего не меняется.
		$this->participants->method( 'listGuestIdsDueForAnonymization' )->willReturn( array() );
		$this->participants->expects( self::never() )->method( 'anonymize' );

		self::assertSame( 0, $this->service->sweepGuests() );
	}

	public function test_participant_with_person_is_never_anonymized(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant( 11 ) );
		$this->participants->expects( self::never() )->method( 'anonymize' );
		$this->tokens->expects( self::never() )->method( 'revoke' );

		self::assertFalse( $this->service->anonymizeParticipant( 9, 'тест' ) );
	}

	public function test_already_anonymized_participant_is_skipped(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant( null, '2026-01-01 00:00:00' ) );
		$this->participants->expects( self::never() )->method( 'anonymize' );

		self::assertFalse( $this->service->anonymizeParticipant( 9, 'тест' ) );
	}

	public function test_anonymize_revokes_tokens_and_sessions(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participants->method( 'anonymize' )->willReturn( true );
		$this->participations->method( 'findByParticipant' )->willReturn( array( $this->participation( 80 ), $this->participation( 81 ) ) );
		$revoked = array();
		$this->tokens->method( 'revoke' )->willReturnCallback( function ( ExamTokenPurpose $purpose, int $id ) use ( &$revoked ): int {
			$revoked[] = $purpose->value . ':' . $id;
			return 1;
		} );
		$this->sessions->expects( self::exactly( 2 ) )->method( 'revokeByParticipation' );

		self::assertTrue( $this->service->anonymizeParticipant( 9, 'тест' ) );
		self::assertSame( array( 'entry:80', 'result:80', 'entry:81', 'result:81' ), $revoked );
	}

	public function test_attempt_and_answers_are_kept(): void {
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participants->method( 'anonymize' )->willReturn( true );
		$this->participations->method( 'findByParticipant' )->willReturn( array( $this->participation( 80 ) ) );
		$this->answers->method( 'listByAttempt' )->willReturn( array(
			new AttemptAnswerDTO( id: 1, attemptId: 55, taskId: 3, answerText: '{"text":"x","files":[701,702]}', isCorrect: null, score: null, maxScore: 1.0, gradedByUserId: null, gradedAt: null ),
		) );
		// Удаляются только файлы гостя; строки попытки и ответов не удаляются ни одним методом сервиса.
		$deleted = array();
		$this->media->method( 'delete' )->willReturnCallback( function ( int $id ) use ( &$deleted ): bool {
			$deleted[] = $id;
			return true;
		} );

		$this->service->anonymizeParticipant( 9, 'тест' );

		self::assertSame( array( 701, 702 ), $deleted );
		$source = (string) file_get_contents( __DIR__ . '/../../../../inc/Services/Exam/ExamRetentionService.php' );
		self::assertDoesNotMatchRegularExpression( '/attempts->(delete|remove)|answers->(delete|remove)/', $source );
	}

	public function test_unpaid_application_cleared_after_thirty_days(): void {
		$this->applications->expects( self::once() )->method( 'clearStaleDrafts' )->with( array( 'expired_unpaid', 'failed', 'cancelled' ), '2026-02-08 07:00:00', 500 )->willReturn( 4 );

		self::assertSame( 4, $this->service->sweepUnpaidApplications() );
	}

	public function test_unreconciled_payment_and_open_resolution_are_kept(): void {
		// Перечень очищаемых состояний не содержит оплаченных и подтверждённых; несверенная оплата и разбор исключены в самих запросах.
		$this->applications->method( 'clearStaleDrafts' )->willReturnCallback( function ( array $states ): int {
			self::assertNotContains( 'paid_needs_resolution', $states );
			self::assertNotContains( 'confirmed', $states );
			self::assertNotContains( 'payment_pending', $states );
			return 0;
		} );
		$this->service->sweepUnpaidApplications();

		$apps = (string) file_get_contents( __DIR__ . '/../../../../inc/Repositories/WPDBRepositories/ExamGuestApplicationRepository.php' );
		self::assertStringContainsString( "pl.payment_state = 'pending'", $apps );
		$participants = (string) file_get_contents( __DIR__ . '/../../../../inc/Repositories/WPDBRepositories/ExamParticipantRepository.php' );
		self::assertStringContainsString( 'paid_needs_resolution', $participants );
		self::assertStringContainsString( 'mr.application_id = ga.id', $participants );
	}

	public function test_woo_orders_are_never_touched(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../../../inc/Services/Exam/ExamRetentionService.php' );

		foreach ( array( 'WooGateway', 'wc_', 'wp_delete_post', 'shop_order' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $source, $needle );
		}
	}

	public function test_manual_anonymize_requires_reason_and_scope(): void {
		self::assertSame( ErrorCode::ExamConflict, $this->refusal( fn () => $this->service->anonymizeByStaff( 10, 9, '  ' ) )->errorCode );

		$this->participants->method( 'find' )->willReturn( $this->participant() );
		$this->participations->method( 'findByParticipant' )->willReturn( array( $this->participation( 80 ) ) );
		$this->events->method( 'find' )->willReturn( $this->examEvent() );
		$this->guard->method( 'canManageEventGuests' )->willReturn( false );
		$this->participants->expects( self::never() )->method( 'anonymize' );

		self::assertSame( ErrorCode::ExamAccess, $this->refusal( fn () => $this->service->anonymizeByStaff( 10, 9, 'Запрос' ) )->errorCode );
	}

	public function test_manual_anonymize_logs_actor_and_reason(): void {
		$this->participants->method( 'find' )->willReturn( $this->participant() );
		$this->participants->method( 'findForUpdate' )->willReturn( $this->participant() );
		$this->participants->method( 'anonymize' )->willReturn( true );
		$this->participations->method( 'findByParticipant' )->willReturn( array( $this->participation( 80 ) ) );
		$this->events->method( 'find' )->willReturn( $this->examEvent() );
		$this->guard->method( 'canManageEventGuests' )->willReturn( true );
		$this->log->expects( self::once() )->method( 'dispatch' )->with(
			LogEvent::ExamGuestAnonymized,
			self::callback( static fn ( $e ): bool => 10 === $e->actorUserId && 'Запрос представителя' === $e->oldLabel )
		);

		$this->service->anonymizeByStaff( 10, 9, 'Запрос представителя' );
	}

	public function test_technical_purge_removes_old_processed_rows_only(): void {
		$this->outbox->expects( self::once() )->method( 'purgeProcessedBefore' )->with( '2026-02-08 07:00:00' )->willReturn( 3 );
		$this->tokenRows->expects( self::once() )->method( 'purgeInactiveBefore' )->with( '2026-02-08 07:00:00' )->willReturn( 2 );
		$this->sessionRows->expects( self::once() )->method( 'purgeInactiveBefore' )->with( '2026-02-08 07:00:00' )->willReturn( 1 );

		self::assertSame( 6, $this->service->purgeTechnical() );
	}

	private function refusal( callable $call ): CodedException {
		try {
			$call();
		} catch ( CodedException $e ) {
			return $e;
		}
		self::fail( 'Ожидался отказ.' );
	}
}
