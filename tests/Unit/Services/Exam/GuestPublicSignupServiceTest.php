<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamSourceDTO;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamSourceRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestPublicSignupService;
use Inc\Services\Exam\GuestSessionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Открытая запись: класс 9 — ОГЭ, 11 — ЕГЭ; запись доступна только у опубликованного проведения с включённой гостевой записью и открытым окном.
 */
class GuestPublicSignupServiceTest extends TestCase {

	use ExamFixtures;

	private ExamSourceRepository&MockObject $sources;
	private ExamEventRepository&MockObject $events;
	private ExamAccessTokenService&MockObject $tokens;
	private GuestSessionService&MockObject $sessions;
	private GuestPublicSignupService $service;

	/** @var array<string, mixed> */
	private array $event = array();

	protected function setUp(): void {
		parent::setUp();

		$this->sources  = $this->createMock( ExamSourceRepository::class );
		$this->events   = $this->createMock( ExamEventRepository::class );
		$this->tokens   = $this->createMock( ExamAccessTokenService::class );
		$this->sessions = $this->createMock( GuestSessionService::class );
		$time           = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );

		$this->event = array( 'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-20 00:00:00' );
		$this->events->method( 'find' )->willReturnCallback( fn () => $this->examEvent( $this->event ) );
		$this->service = new GuestPublicSignupService( $this->sources, $this->events, $this->tokens, $this->sessions, $time );
	}

	private function source( int $grade, int $id = 40 ): ExamSourceDTO {
		return ExamSourceDTO::fromArray( array(
			'id' => (string) $id, 'event_id' => '3', 'school_key' => 'public', 'school_name' => 'Открытая запись', 'school_name_normalized' => 'открытая запись',
			'grade' => (string) $grade, 'teacher_name' => '—', 'label' => 'Открытая запись', 'is_active' => '1', 'key_generation' => '0', 'key_revoked_at' => null,
			'created_by_user_id' => '10', 'version' => '1', 'created_at' => '2026-02-20 10:00:00', 'updated_at' => '2026-02-20 10:00:00',
		) );
	}

	public function test_options_show_only_grades_with_open_registration(): void {
		$this->sources->method( 'listActivePublic' )->willReturn( array( $this->source( 11 ) ) );

		$options = $this->service->options();

		self::assertSame( array( 9, 11 ), array_column( $options, 'grade' ) );
		self::assertSame( array( false, true ), array_column( $options, 'available' ) );
		self::assertSame( '11 класс — ЕГЭ', $options[1]['label'] );
		self::assertSame( '9 класс — ОГЭ', $options[0]['label'] );
	}

	public function test_open_for_grade_opens_invitation_session_by_public_source(): void {
		$this->sources->method( 'listActivePublic' )->willReturn( array( $this->source( 9, 41 ) ) );
		$this->tokens->method( 'currentGeneration' )->with( ExamTokenPurpose::Invitation, 41 )->willReturn( 1 );
		$this->sessions->expects( self::once() )->method( 'openInvitation' )->with( 41, 1 )->willReturn( 'cookie' );

		self::assertSame( 'cookie', $this->service->openForGrade( 9 ) );
	}

	public function test_no_session_for_grade_without_registration(): void {
		$this->sources->method( 'listActivePublic' )->willReturn( array( $this->source( 11 ) ) );
		$this->sessions->expects( self::never() )->method( 'openInvitation' );

		self::assertNull( $this->service->openForGrade( 9 ) );
		self::assertNull( $this->service->openForGrade( 10 ), 'Класс вне 9/11 не открывается.' );
	}

	public function test_closed_window_unpublished_or_guest_disabled_event_is_not_available(): void {
		$this->sources->method( 'listActivePublic' )->willReturn( array( $this->source( 11 ) ) );

		foreach ( array(
			array( 'registration_closes_at' => '2026-03-10 07:00:00' ),
			array( 'registration_opens_at' => '2026-03-11 00:00:00' ),
			array( 'guest_registration_enabled' => '0' ),
			array( 'status' => 'draft' ),
		) as $override ) {
			$this->event = array_merge( array( 'status' => 'published', 'guest_registration_enabled' => '1', 'registration_opens_at' => '2026-03-01 00:00:00', 'registration_closes_at' => '2026-03-20 00:00:00' ), $override );
			self::assertNull( $this->service->openForGrade( 11 ), json_encode( $override ) );
		}
	}
}
