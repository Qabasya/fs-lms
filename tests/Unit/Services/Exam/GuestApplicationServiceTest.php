<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\Person\UserDTO;
use Inc\DTO\RequestContextDTO;
use Inc\Enums\Access\UserRole;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\OptionsRepositories\UserRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\GuestApplicationService;
use Inc\Services\Exam\GuestIdentity;
use Inc\Services\Exam\GuestParticipantMaterializer;
use Inc\Services\Person\ConsentService;
use Inc\Services\Security\PiiCryptoService;
use Inc\Services\Security\RateLimitService;
use Inc\Services\Shared\PluginConfig;
use Inc\Shared\CodedException;
use Inc\Shared\GuestFormException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Заявка гостя: данные источника, шифрование, согласия, правила формы, лимиты, участник только при подтверждении.
 */
#[AllowMockObjectsWithoutExpectations]
class GuestApplicationServiceTest extends TestCase {

	use ExamFixtures;

	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamParticipantRepository&MockObject $participants;
	private ExamParticipationRepository&MockObject $participations;
	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamHoldService&MockObject $holds;
	private PiiCryptoService&MockObject $crypto;
	private ConsentService&MockObject $consents;
	private PluginConfig&MockObject $config;
	private ExamOutbox&MockObject $outbox;
	private RateLimitService&MockObject $rate;
	private UserRepository&MockObject $users;
	private GuestApplicationService $service;

	/** @var array<string, mixed> */
	private array $captured = array();
	/** @var list<string> */
	private array $recordedConsents = array();

	protected function setUp(): void {
		parent::setUp();

		$this->applications   = $this->createMock( ExamGuestApplicationRepository::class );
		$this->participants   = $this->createMock( ExamParticipantRepository::class );
		$this->participations = $this->createMock( ExamParticipationRepository::class );
		$this->events         = $this->createMock( ExamEventRepository::class );
		$this->sessions       = $this->createMock( ExamSessionRepository::class );
		$this->holds          = $this->createMock( ExamHoldService::class );
		$this->crypto         = $this->createMock( PiiCryptoService::class );
		$this->consents       = $this->createMock( ConsentService::class );
		$this->config         = $this->createMock( PluginConfig::class );
		$this->outbox         = $this->createMock( ExamOutbox::class );
		$this->rate           = $this->createMock( RateLimitService::class );
		$this->users          = $this->createMock( UserRepository::class );

		$this->crypto->method( 'encrypt' )->willReturnCallback( static fn ( string $v ): string => 'ENC(' . base64_encode( $v ) . ')' );
		$this->crypto->method( 'hash' )->willReturnCallback( static fn ( string $v ): string => hash( 'sha256', mb_strtolower( trim( $v ) ) ) );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'guest_registration_enabled' => '1' ) ) );
		$this->sessions->method( 'find' )->willReturnCallback( fn ( int $id ): ?\Inc\DTO\Exam\ExamSessionDTO => 7 === $id ? $this->examSession() : ( 99 === $id ? $this->examSession( array( 'id' => '99', 'event_id' => '4' ) ) : null ) );
		$this->config->method( 'examHoldMinutes' )->willReturn( 20 );
		$this->config->method( 'examIpActiveHoldsLimit' )->willReturn( 40 );
		$this->config->method( 'examIpHourlyLimit' )->willReturn( 60 );
		$this->config->method( 'examSourceActiveHoldsLimit' )->willReturn( 60 );
		$this->rate->method( 'ipHash' )->willReturn( str_repeat( 'a', 64 ) );
		$this->rate->method( 'allowExamHoldCreation' )->willReturn( true );
		$this->consents->method( 'recordSelfConsent' )->willReturnCallback( function ( ?int $appId, string $type ): int {
			$this->recordedConsents[] = $type;
			return count( $this->recordedConsents ) * 10;
		} );
		$this->holds->method( 'capture' )->willReturnCallback( function ( array $data ): ExamGuestApplicationDTO {
			$this->captured = $data;
			return ExamGuestApplicationDTO::fromArray( array(
				'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => $data['identity_hash'], 'state' => 'hold', 'is_held' => '1',
				'request_key' => $data['request_key'], 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
			) );
		} );

		$this->service = $this->build();
	}

	private function build(): GuestApplicationService {
		$identity = new GuestIdentity( $this->crypto );

		return new GuestApplicationService(
			$this->applications, $this->participants, $this->participations, $this->events, $this->sessions, $this->holds, $identity, $this->crypto, $this->consents,
			$this->config, $this->outbox, $this->rate, $this->users,
			new GuestParticipantMaterializer( $this->participants, $this->crypto, $identity, $this->createStub( \Inc\Services\Exam\ExamTime::class ) )
		);
	}

	/** @param array<string, mixed> $override */
	private function source( array $override = array() ): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array_merge( array(
			'id' => '14', 'event_id' => '3', 'school_key' => 'sch5', 'school_name' => 'Школа 5', 'school_name_normalized' => 'школа 5', 'grade' => '11', 'teacher_name' => 'Петрова А. И.',
			'label' => 'Школа 5 · 11', 'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1',
			'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		), $override ) );
	}

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function form( array $override = array() ): array {
		return array_merge( array(
			'last_name' => 'Иванов', 'first_name' => 'Пётр', 'middle_name' => '', 'phone' => '+7 (900) 111-22-33', 'messenger' => '@ivan',
			'session_id' => 7, 'consents' => array( 'pd_processing' ),
			// Попытка подмены школы и класса — должна быть проигнорирована.
			'school_name' => 'Чужая школа', 'grade' => 9,
		), $override );
	}

	private function ctx( int $userId = 0 ): RequestContextDTO {
		return new RequestContextDTO( '10.0.0.1', 'UA', $userId );
	}

	private function apply( array $form = array(), ?ExamSourceDTO $source = null, int $userId = 0, ?int $staff = null ): ExamGuestApplicationDTO {
		return $this->service->apply( $source ?? $this->source(), $this->form( $form ), $this->ctx( $userId ), 'req-1', $staff );
	}

	private function assertFormError( string $field, ErrorCode $code, callable $call ): void {
		try {
			$call();
			self::fail( 'Ожидалась ошибка поля ' . $field );
		} catch ( GuestFormException $e ) {
			self::assertSame( $field, $e->field );
			self::assertSame( $code, $e->errorCode );
		}
	}

	public function test_apply_takes_school_and_grade_from_source_not_from_form(): void {
		$this->apply();

		$snapshot = json_decode( $this->captured['source_snapshot'], true );
		self::assertSame( 'Школа 5', $snapshot['school_name'] );
		self::assertSame( 11, $snapshot['grade'] );
		self::assertSame( 'Петрова А. И.', $snapshot['teacher_name'] );
	}

	public function test_apply_stores_form_encrypted(): void {
		$this->apply();

		$stored = (string) base64_decode( $this->captured['draft_enc'], true );
		self::assertStringStartsWith( 'ENC(', $stored );
		self::assertStringNotContainsString( 'Иванов', $this->captured['draft_enc'] );
		$plain = json_decode( base64_decode( substr( $stored, 4, -1 ) ), true );
		self::assertSame( 'Иванов', $plain['last_name'] );
		self::assertSame( '@ivan', $plain['messenger'] );
	}

	public function test_apply_records_consents_and_keeps_refs(): void {
		$this->apply( array( 'consents' => array( 'pd_processing', 'pd_transfer', 'evil' ) ) );

		self::assertSame( array( 'pd_processing', 'pd_transfer' ), $this->recordedConsents );
		self::assertSame( array( 'pd_processing' => 10, 'pd_transfer' => 20 ), json_decode( $this->captured['consent_refs'], true ) );
	}

	public function test_pd_consent_is_required(): void {
		$this->consents->expects( self::never() )->method( 'recordSelfConsent' );

		$this->assertFormError( 'consent_pd', ErrorCode::ExamConsent, fn () => $this->apply( array( 'consents' => array( 'pd_transfer' ) ) ) );
	}

	public function test_transfer_consent_is_optional_and_stored_separately(): void {
		$this->apply();

		self::assertSame( array( 'pd_processing' ), $this->recordedConsents );
		self::assertArrayNotHasKey( 'pd_transfer', json_decode( $this->captured['consent_refs'], true ) );
	}

	public function test_apply_rejected_for_revoked_or_inactive_source(): void {
		foreach ( array( array( 'is_active' => '0' ), array( 'key_revoked_at' => '2026-03-02 00:00:00' ) ) as $override ) {
			try {
				$this->apply( array(), $this->source( $override ) );
				self::fail( 'Ожидался отказ.' );
			} catch ( CodedException $e ) {
				self::assertSame( ErrorCode::ExamClosed, $e->errorCode );
			}
		}
	}

	public function test_apply_rejected_when_guest_registration_disabled(): void {
		$this->events = $this->createMock( ExamEventRepository::class );
		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'guest_registration_enabled' => '0' ) ) );

		$this->expectException( CodedException::class );
		$this->build()->apply( $this->source(), $this->form(), $this->ctx(), 'req-1' );
	}

	public function test_session_of_other_event_is_rejected(): void {
		$this->assertFormError( 'session_id', ErrorCode::ExamConflict, fn () => $this->apply( array( 'session_id' => 99 ) ) );
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>}> */
	public static function invalidForms(): array {
		return array(
			'нет фамилии'        => array( 'last_name', array( 'last_name' => ' ' ) ),
			'длинная фамилия'    => array( 'last_name', array( 'last_name' => str_repeat( 'а', 101 ) ) ),
			'нет имени'          => array( 'first_name', array( 'first_name' => '' ) ),
			'длинное отчество'   => array( 'middle_name', array( 'middle_name' => str_repeat( 'а', 101 ) ) ),
			'короткий телефон'   => array( 'phone', array( 'phone' => '+7 900 111' ) ),
			'нет телефона'       => array( 'phone', array( 'phone' => '' ) ),
			'длинный мессенджер' => array( 'messenger', array( 'messenger' => str_repeat( 'x', 101 ) ) ),
			'нет сеанса'         => array( 'session_id', array( 'session_id' => 0 ) ),
		);
	}

	/** @param array<string, mixed> $override */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidForms' )]
	public function test_server_validation_rules( string $field, array $override ): void {
		$this->holds->expects( self::never() )->method( 'capture' );

		$this->assertFormError( $field, ErrorCode::ExamConflict, fn () => $this->apply( $override ) );
	}

	public function test_middle_name_and_messenger_are_optional(): void {
		$this->apply( array( 'middle_name' => '', 'messenger' => '' ) );

		self::assertNotEmpty( $this->captured );
	}

	public function test_second_application_of_same_identity_is_conflict_without_details(): void {
		$holds = $this->createMock( ExamHoldService::class );
		$holds->method( 'capture' )->willThrowException( new CodedException( ErrorCode::ExamConflict, 'Заявка на этот экзамен уже оформлена.' ) );
		$this->holds = $holds;

		try {
			$this->build()->apply( $this->source(), $this->form(), $this->ctx(), 'req-1' );
			self::fail( 'Ожидался конфликт.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamConflict, $e->errorCode );
			self::assertStringNotContainsString( 'Иванов', $e->getMessage() );
		}
	}

	public function test_two_children_with_same_phone_get_two_applications(): void {
		$this->apply();
		$first = $this->captured['identity_hash'];
		$this->apply( array( 'last_name' => 'Иванова', 'first_name' => 'Мария' ) );

		self::assertNotSame( $first, $this->captured['identity_hash'] );
	}

	public function test_logged_in_student_is_redirected_to_cabinet_not_registered_as_guest(): void {
		$this->users->method( 'getById' )->willReturn( new UserDTO( 55, 's@x', 'Ученик', UserRole::FSStudent ) );
		$this->holds->expects( self::never() )->method( 'capture' );

		$this->assertFormError( 'cabinet', ErrorCode::ExamAccess, fn () => $this->apply( array(), null, 55 ) );
	}

	public function test_participant_is_created_only_on_confirmation(): void {
		$this->participants->expects( self::never() )->method( 'insert' );

		$this->apply();
	}

	public function test_duplicate_candidates_by_phone_are_hints_only(): void {
		$me = ExamParticipantDTO::fromArray( array( 'id' => 1, 'name_hash' => 'N1', 'phone_hash' => 'P1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
		$other = ExamParticipantDTO::fromArray( array( 'id' => 2, 'name_hash' => 'N2', 'phone_hash' => 'P1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
		$this->participants->method( 'find' )->willReturnCallback( static fn ( int $id ) => 1 === $id ? $me : $other );
		$this->participations->method( 'findByEvent' )->willReturn( array(
			ExamParticipationDTO::fromArray( array( 'id' => 11, 'event_id' => 3, 'participant_id' => 2, 'audience' => 'guest', 'transfer_allowed' => 0, 'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) ),
		) );

		$candidates = $this->service->duplicateCandidates( 3, 1 );

		self::assertSame( array( array( 'participant_id' => 2, 'participation_id' => 11, 'match' => 'phone' ) ), $candidates );
	}

	// ── Лимиты ─────────────────────────────────────────────────────────────────────────────────────

	public function test_forty_first_active_hold_from_ip_is_limited(): void {
		$this->applications->method( 'countHeldByIp' )->willReturn( 40 );
		$this->holds->expects( self::never() )->method( 'capture' );

		try {
			$this->apply();
			self::fail( 'Ожидался лимит.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamLimit, $e->errorCode );
		}
	}

	public function test_thirty_holds_from_one_ip_pass(): void {
		$this->applications->method( 'countHeldByIp' )->willReturn( 30 );

		$this->apply();

		self::assertNotEmpty( $this->captured );
	}

	public function test_source_limit_writes_outbox_event(): void {
		$this->applications->method( 'countHeldBySource' )->willReturn( 60 );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::SourceLimitExceeded, 'source', 14, 1, array( 'event_id' => 3, 'source_id' => 14 ) );

		$this->expectException( CodedException::class );
		$this->apply();
	}

	public function test_staff_application_skips_ip_limits_but_not_source_limit(): void {
		$this->applications->method( 'countHeldByIp' )->willReturn( 500 );

		$this->apply( array(), null, 0, 10 );

		self::assertSame( 10, $this->captured['created_by_user_id'] );
	}

	public function test_application_not_accepted_when_consent_was_not_stored(): void {
		$consents = $this->createMock( ConsentService::class );
		$consents->method( 'recordSelfConsent' )->willReturn( 0 );
		$this->consents = $consents;
		$this->holds->expects( self::never() )->method( 'capture' );

		$this->expectException( \RuntimeException::class );
		$this->build()->apply( $this->source(), $this->form(), $this->ctx(), 'req-1' );
	}
}
