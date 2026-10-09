<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamSourceDTO;
use Inc\DTO\RequestContextDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Enums\Log\ErrorCode;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestApplicationService;
use Inc\Services\Exam\GuestOnSiteService;
use Inc\Services\Exam\GuestParticipantMaterializer;
use Inc\Shared\CodedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Добавление гостя на месте: право, конец сеанса, подтверждение дубля, ссылка на оплату — только с тем же потоком оплаты.
 */
#[AllowMockObjectsWithoutExpectations]
class GuestOnSiteServiceTest extends TestCase {

	use ExamFixtures;

	private GuestApplicationService&MockObject $applications;
	private ExamAccessGuard&MockObject $guard;
	private ExamSessionRepository&MockObject $sessions;
	private ExamSourceRepository&MockObject $sources;
	private ExamGuestApplicationRepository&MockObject $appRepo;
	private ExamAccessTokenService&MockObject $tokens;
	private string $nowUtc = '2026-03-10 07:00:00';
	private GuestOnSiteService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->applications = $this->createMock( GuestApplicationService::class );
		$this->guard        = $this->createMock( ExamAccessGuard::class );
		$this->sessions     = $this->createMock( ExamSessionRepository::class );
		$this->sources      = $this->createMock( ExamSourceRepository::class );
		$this->appRepo      = $this->createMock( ExamGuestApplicationRepository::class );
		$this->tokens       = $this->createMock( ExamAccessTokenService::class );
		$events             = $this->createMock( ExamEventRepository::class );
		$time               = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturnCallback( fn (): string => $this->nowUtc );
		$time->method( 'toLocal' )->willReturnArgument( 0 );
		$time->method( 'secondsUntil' )->willReturn( 1200 );

		$events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published' ) ) );
		$this->guard->method( 'canManageEventGuests' )->willReturn( true );
		$this->sessions->method( 'find' )->willReturn( $this->examSession( array( 'planned_end_at' => '2026-03-10 10:55:00' ) ) );
		$this->sources->method( 'find' )->willReturn( ExamSourceDTO::fromArray( array(
			'id' => '14', 'event_id' => '3', 'school_name' => 'Центр', 'school_name_normalized' => 'центр', 'grade' => '11', 'teacher_name' => 'Т', 'label' => 'l',
			'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );
		$this->applications->method( 'hashesOf' )->willReturn( array( 'name' => 'N', 'phone' => 'P' ) );
		$this->applications->method( 'apply' )->willReturn( $this->application() );
		$this->tokens->method( 'issue' )->willReturn( str_repeat( 'f', 64 ) );

		$this->service = $this->build();
	}

	private function build(): GuestOnSiteService {
		return new GuestOnSiteService(
			$this->applications, $this->guard, $this->events(), $this->sessions, $this->sources,
			$this->appRepo, $this->createMock( ExamParticipantRepository::class ), $this->createMock( GuestParticipantMaterializer::class ), $this->tokens, $this->time()
		);
	}

	private function events(): ExamEventRepository {
		$events = $this->createMock( ExamEventRepository::class );
		$events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published' ) ) );

		return $events;
	}

	private function time(): ExamTime {
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturnCallback( fn (): string => $this->nowUtc );
		$time->method( 'toLocal' )->willReturnArgument( 0 );
		$time->method( 'secondsUntil' )->willReturn( 1200 );

		return $time;
	}

	private function application(): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'hold', 'is_held' => '1',
			'hold_expires_at' => '2026-03-10 07:20:00', 'request_key' => 'rk', 'version' => '1', 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		) );
	}

	/** @return array<string, mixed> */
	private function form(): array {
		return array( 'last_name' => 'Иванов', 'first_name' => 'Пётр', 'phone' => '9001112233', 'consents' => array( 'pd_processing' ) );
	}

	private function ctx(): RequestContextDTO {
		return new RequestContextDTO( '', '', 10 );
	}

	public function test_add_creates_application_by_staff_and_returns_pay_url(): void {
		$this->applications->method( 'duplicateCandidatesByHashes' )->willReturn( array() );
		$this->applications->expects( self::once() )->method( 'apply' )->with( self::anything(), self::anything(), self::anything(), 'rk', 10 )->willReturn( $this->application() );
		$this->tokens->expects( self::once() )->method( 'issue' )->with( ExamTokenPurpose::Payment, 9, 10, '2026-03-10 07:20:00' );

		$result = $this->build()->add( 10, 7, 14, $this->form(), $this->ctx(), 'rk', false );

		self::assertSame( 'created', $result['status'] );
		self::assertStringContainsString( 'pay=' . str_repeat( 'f', 64 ), $result['pay_url'] );
	}

	public function test_add_denied_after_planned_end(): void {
		$this->nowUtc = '2026-03-10 11:00:00';
		$this->applications->expects( self::never() )->method( 'apply' );

		try {
			$this->build()->add( 10, 7, 14, $this->form(), $this->ctx(), 'rk', true );
			self::fail( 'Ожидался отказ.' );
		} catch ( CodedException $e ) {
			self::assertSame( ErrorCode::ExamClosed, $e->errorCode );
		}
	}

	public function test_add_returns_duplicate_candidates_before_creating(): void {
		$this->applications->method( 'duplicateCandidatesByHashes' )->willReturn( array( array( 'participant_id' => 2, 'participation_id' => 5, 'match' => 'phone' ) ) );
		$this->applications->expects( self::never() )->method( 'apply' );

		$result = $this->build()->add( 10, 7, 14, $this->form(), $this->ctx(), 'rk', false );

		self::assertSame( 'needs_confirmation', $result['status'] );
		self::assertSame( 'phone', $result['candidates'][0]['match'] );
	}

	public function test_confirmed_not_duplicate_skips_candidate_check(): void {
		$this->applications->expects( self::never() )->method( 'duplicateCandidatesByHashes' );

		$result = $this->build()->add( 10, 7, 14, $this->form(), $this->ctx(), 'rk', true );

		self::assertSame( 'created', $result['status'] );
	}

	public function test_denied_without_guest_rights_on_event(): void {
		$guard = $this->createMock( ExamAccessGuard::class );
		$guard->method( 'canManageEventGuests' )->willReturn( false );
		$service = new GuestOnSiteService( $this->applications, $guard, $this->events(), $this->sessions, $this->sources, $this->appRepo, $this->createMock( ExamParticipantRepository::class ), $this->createMock( GuestParticipantMaterializer::class ), $this->tokens, $this->time() );

		$this->expectException( CodedException::class );
		$service->add( 10, 7, 14, $this->form(), $this->ctx(), 'rk', true );
	}

	public function test_source_of_other_event_is_rejected(): void {
		$sources = $this->createMock( ExamSourceRepository::class );
		$sources->method( 'find' )->willReturn( ExamSourceDTO::fromArray( array(
			'id' => '15', 'event_id' => '99', 'school_name' => 'X', 'school_name_normalized' => 'x', 'grade' => '9', 'teacher_name' => 'Т', 'label' => 'l',
			'is_active' => '1', 'key_generation' => '1', 'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );
		$service = new GuestOnSiteService( $this->applications, $this->guard, $this->events(), $this->sessions, $sources, $this->appRepo, $this->createMock( ExamParticipantRepository::class ), $this->createMock( GuestParticipantMaterializer::class ), $this->tokens, $this->time() );

		$this->expectException( CodedException::class );
		$service->add( 10, 7, 15, $this->form(), $this->ctx(), 'rk', true );
	}

	public function test_reissue_denied_for_expired_hold(): void {
		$expired = ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'expired_unpaid', 'is_held' => '0',
			'request_key' => 'rk', 'version' => '1', 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		) );
		$this->appRepo->method( 'find' )->willReturn( $expired );
		$this->tokens->expects( self::never() )->method( 'issue' );

		$this->expectException( CodedException::class );
		$this->build()->reissuePayLink( 10, 9 );
	}

	public function test_no_payment_bypass_exists_in_exam_code(): void {
		$root = dirname( __DIR__, 4 ) . '/inc';
		foreach ( array( '/Callbacks/Exam', '/Services/Exam' ) as $dir ) {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir ) ) as $file ) {
				if ( $file->isFile() && 'php' === $file->getExtension() ) {
					self::assertDoesNotMatchRegularExpression( '/setPaid|markPaid|mark_paid|free_admission/i', (string) file_get_contents( $file->getPathname() ), $file->getFilename() );
				}
			}
		}
	}
}
