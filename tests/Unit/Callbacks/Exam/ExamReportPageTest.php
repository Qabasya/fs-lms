<?php

declare( strict_types=1 );

namespace Unit\Callbacks\Exam;

use Inc\Callbacks\Exam\ExamReportPageCallbacks;
use Inc\DTO\Assessment\AttemptDTO;
use Inc\DTO\Exam\ExamAccessTokenDTO;
use Inc\DTO\Exam\ExamParticipantDTO;
use Inc\DTO\Exam\ExamParticipationDTO;
use Inc\DTO\Exam\ExamReportDTO;
use Inc\DTO\Exam\GuestPageOutcome;
use Inc\Enums\Exam\ExamTokenPurpose;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipationRepository;
use Inc\Repositories\WPDBRepositories\ExamReportMemberRepository;
use Inc\Repositories\WPDBRepositories\ExamReportRepository;
use Inc\Services\Exam\ExamAccessTokenService;
use Inc\Services\Exam\ExamConductService;
use Inc\Services\Exam\ExamReportService;
use Inc\Services\Exam\ExamReportViewService;
use Inc\Services\Exam\ExamScoreService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use Inc\Services\Exam\GuestResultViewService;
use Inc\Services\Exam\GuestSessionService;
use Inc\Services\Security\RateLimitService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Страница школьного отчёта: ключ → кука → адрес без ключа, отказы 404, разбор только участника отчёта, заглушки, чистота разметки.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamReportPageTest extends TestCase {

	use ExamFixtures;

	private ExamAccessTokenService&MockObject $tokens;
	private GuestSessionService&MockObject $sessions;
	private ExamReportRepository&MockObject $reports;
	private ExamReportService&MockObject $reportService;
	private ExamReportViewService&MockObject $view;
	private RateLimitService&MockObject $rate;
	private ExamReportPageCallbacks $cb;

	protected function setUp(): void {
		parent::setUp();
		$_GET    = array();
		$_COOKIE = array();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.7';

		$this->tokens        = $this->createMock( ExamAccessTokenService::class );
		$this->sessions      = $this->createMock( GuestSessionService::class );
		$this->reports       = $this->createMock( ExamReportRepository::class );
		$this->reportService = $this->createMock( ExamReportService::class );
		$this->view          = $this->createMock( ExamReportViewService::class );
		$this->rate          = $this->createMock( RateLimitService::class );
		$time                = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'addMinutes' )->willReturn( '2026-03-11 07:00:00' );

		$this->cb = new ExamReportPageCallbacks( $this->tokens, $this->sessions, $this->reports, $this->reportService, $this->view, $this->rate, $time );
	}

	protected function tearDown(): void {
		$_GET = $_COOKIE = array();
		parent::tearDown();
	}

	private function report(): ExamReportDTO {
		return ExamReportDTO::fromArray( array(
			'id' => 7, 'event_id' => 3, 'title' => 'Школа 5', 'owner_user_id' => 1, 'expires_at' => '2026-06-08 07:00:00', 'version' => 1, 'created_at' => '2026-03-10 07:00:00',
		) );
	}

	private function token( string $purpose = 'report' ): ExamAccessTokenDTO {
		return ExamAccessTokenDTO::fromArray( array(
			'id' => 1, 'purpose' => $purpose, 'target_id' => 7, 'token_hash' => str_repeat( 'a', 64 ), 'generation' => 2, 'issuer_user_id' => 10, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_report_key_opens_report_and_redirects_without_key(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		$this->tokens->expects( self::once() )->method( 'exchange' )->with( ExamTokenPurpose::Report, str_repeat( 'a', 64 ) )->willReturn( $this->token() );
		$this->reports->method( 'find' )->willReturn( $this->report() );
		$this->reportService->method( 'isOpen' )->willReturn( true );
		$this->sessions->expects( self::once() )->method( 'openReport' )->willReturn( str_repeat( 'f', 64 ) );

		$outcome = $this->cb->handleReportPage();

		self::assertSame( GuestPageOutcome::REDIRECT, $outcome->kind );
		self::assertStringNotContainsString( 'k=', $outcome->url );
		self::assertSame( array( GuestSessionService::COOKIE_REPORT => str_repeat( 'f', 64 ) ), $outcome->cookies );
	}

	public function test_revoked_expired_or_wrong_purpose_key_is_404(): void {
		$_GET['k'] = str_repeat( 'a', 64 );
		// Ключ другого назначения (вход, результат) — exchange(Report, …) отвечает null.
		$this->tokens->method( 'exchange' )->willReturn( null );
		$this->rate->expects( self::atLeastOnce() )->method( 'registerInvitationFailure' );
		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleReportPage()->kind );

		// Ключ верный, но отчёт отозван или истёк.
		$this->tokens = $this->createMock( ExamAccessTokenService::class );
		$this->tokens->method( 'exchange' )->willReturn( $this->token() );
		$reportService = $this->createMock( ExamReportService::class );
		$reportService->method( 'isOpen' )->willReturn( false );
		$this->reports->method( 'find' )->willReturn( $this->report() );
		$cb = new ExamReportPageCallbacks( $this->tokens, $this->sessions, $this->reports, $reportService, $this->view, $this->rate, $this->createStub( ExamTime::class ) );
		$this->sessions->expects( self::never() )->method( 'openReport' );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $cb->handleReportPage()->kind );
	}

	public function test_page_without_session_is_404(): void {
		$this->sessions->method( 'currentReportId' )->willReturn( null );

		self::assertSame( GuestPageOutcome::NOT_FOUND, $this->cb->handleReportPage()->kind );
		self::assertSame( '', $this->cb->renderReportPage() );
	}

	public function test_page_never_accepts_attempt_or_participation_id(): void {
		$_GET = array( 'attempt' => '55', 'attempt_id' => '55', 'participation' => '80', 'participation_id' => '80', 'report_id' => '8' );
		$this->sessions->method( 'currentReportId' )->willReturn( 7 );
		$this->reports->method( 'find' )->with( 7 )->willReturn( $this->report() );
		$this->reportService->method( 'isOpen' )->willReturn( true );
		// Из адреса читается только номер строки `row`: без него разбора нет, отчёт — из сессии, а не из адреса.
		$this->view->expects( self::once() )->method( 'build' )->with( self::anything(), null )->willReturn( $this->pageData() );

		$this->cb->renderReportPage();
	}

	public function test_row_number_is_passed_as_position_only(): void {
		$_GET['row'] = '2';
		$this->sessions->method( 'currentReportId' )->willReturn( 7 );
		$this->reports->method( 'find' )->willReturn( $this->report() );
		$this->reportService->method( 'isOpen' )->willReturn( true );
		$this->view->expects( self::once() )->method( 'build' )->with( self::anything(), 2 )->willReturn( $this->pageData() );

		$this->cb->renderReportPage();
	}

	/** @return array<string, mixed> */
	private function pageData(): array {
		return array(
			'title' => 'Школа 5', 'event' => 'Пробный ЕГЭ', 'expires_at' => '2026-06-08 07:00:00', 'review' => null,
			'rows' => array( array( 'n' => 1, 'name' => 'Иванов Пётр', 'status' => 'Проверено', 'caption' => '84 из 100', 'open' => true ), array( 'n' => 2, 'name' => ExamReportViewService::PLACEHOLDER, 'status' => '', 'caption' => '', 'open' => false ) ),
			'stats' => array( 'participants' => 2, 'average' => '84', 'pending' => 0 ),
		);
	}

	private function renderTemplate( array $data ): string {
		$data += array( 'page_url' => 'https://x.test/exam-report/', 'crumbs' => array() );
		ob_start();
		( static function ( array $vars, string $file ): void {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $file;
		} )( $data, __DIR__ . '/../../../../templates/frontend/exam-report.php' );

		return (string) ob_get_clean();
	}

	public function test_html_has_no_contacts_tokens_or_internal_ids(): void {
		$html = $this->renderTemplate( $this->pageData() );

		foreach ( array( 'phone', 'messenger', 'телефон', 'мессенджер', '/exam-entry/', '/exam-result/', 'attempt', 'participation', 'k=', 'csv', 'Оценить', 'печать' ) as $needle ) {
			self::assertStringNotContainsStringIgnoringCase( $needle, $html, $needle );
		}
		// Ссылка раскрытия — только порядковый номер.
		self::assertStringContainsString( 'row=1', $html );
		self::assertStringNotContainsString( 'row=2', $html, 'У заглушки кнопки раскрытия нет.' );
		self::assertStringContainsString( ExamReportViewService::PLACEHOLDER, $html );
	}

	public function test_view_service_source_never_reads_contacts(): void {
		$src = (string) file_get_contents( __DIR__ . '/../../../../inc/Services/Exam/ExamReportViewService.php' );

		foreach ( array( 'phoneEnc', 'messengerEnc', 'decryptContacts', 'issue(' ) as $needle ) {
			self::assertStringNotContainsString( $needle, $src, $needle );
		}
	}

	public function test_headers_noindex_no_store_no_referrer(): void {
		$responder = (string) file_get_contents( __DIR__ . '/../../../../inc/Services/Exam/GuestPageResponder.php' );
		$controller = (string) file_get_contents( __DIR__ . '/../../../../inc/Controllers/Exam/ExamGuestPageController.php' );

		self::assertStringContainsString( 'X-Robots-Tag: noindex, nofollow', $responder );
		self::assertStringContainsString( 'no-store', $responder );
		self::assertStringContainsString( 'Referrer-Policy: no-referrer', $responder );
		// Страница отчёта идёт через тот же ответчик и входит в список исключаемых из карты сайта и поиска.
		self::assertMatchesRegularExpression( '/handleReportPage.*?sendHeaders\(\)/s', $controller );
		self::assertStringContainsString( 'PageRoutes::ExamReport', substr( $controller, (int) strpos( $controller, 'function guestRoutes' ) ) );
	}

	// ── Построение таблицы (ExamReportViewService) ──────────────────────────────────────────────

	private function viewService( ExamReportService $reportService, ExamParticipantDTO $participant, ?GuestResultViewService $result = null, array $ids = array( 80, 81 ) ): ExamReportViewService {
		$members = $this->createMock( ExamReportMemberRepository::class );
		$members->method( 'listParticipationIds' )->willReturn( $ids );
		$participations = $this->createMock( ExamParticipationRepository::class );
		$participations->method( 'find' )->willReturnCallback( static fn ( int $id ): ExamParticipationDTO => ExamParticipationDTO::fromArray( array(
			'id' => $id, 'event_id' => 3, 'participant_id' => 9, 'audience' => 'guest', 'current_attempt_id' => 55, 'transfer_allowed' => 1,
			'version' => 1, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) ) );
		$participants = $this->createMock( ExamParticipantRepository::class );
		$participants->method( 'find' )->willReturn( $participant );
		$events = $this->createMock( ExamEventRepository::class );
		$events->method( 'find' )->willReturn( $this->examEvent() );
		$attempts = $this->createMock( AssessmentAttemptRepository::class );
		$attempts->method( 'find' )->willReturn( AttemptDTO::fromArray( array(
			'id' => 55, 'assessment_id' => 5, 'student_person_id' => null, 'group_id' => null, 'attempt_number' => 1, 'status' => 'submitted',
			'started_at' => '2026-03-10 10:00:00', 'deadline_at' => '2026-03-10 14:00:00',
		) ) );
		$scores = $this->createMock( ExamScoreService::class );
		$scores->method( 'summarize' )->willReturn( array( 'direction' => 'ege', 'primary' => 37, 'primary_max' => 56, 'secondary' => 84, 'secondary_max' => 100, 'final' => true ) );
		$scores->method( 'caption' )->willReturn( '84 из 100' );
		$mat = $this->createMock( GuestParticipantMaterializer::class );
		$mat->method( 'displayName' )->willReturn( 'Иванов Пётр' );

		return new ExamReportViewService( $members, $participations, $participants, $events, $attempts, $reportService, $this->createMock( ExamConductService::class ), $scores, $result ?? $this->createMock( GuestResultViewService::class ), $mat );
	}

	private function participant( ?string $anonymizedAt = null ): ExamParticipantDTO {
		return ExamParticipantDTO::fromArray( array( 'id' => 9, 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00', 'anonymized_at' => $anonymizedAt ) );
	}

	public function test_review_block_is_built_only_for_report_member(): void {
		$result = $this->createMock( GuestResultViewService::class );
		$result->expects( self::once() )->method( 'build' )->with( 81 )->willReturn( array( 'revealed' => true, 'caption' => '84 из 100', 'units' => array(), 'counts' => array(), 'tasks' => array() ) );
		$service = $this->viewService( $this->createMock( ExamReportService::class ), $this->participant(), $result );

		$data = $service->build( $this->report(), 2 );

		self::assertSame( 2, $data['review']['row'] );
	}

	public function test_row_number_out_of_range_shows_no_review(): void {
		$result = $this->createMock( GuestResultViewService::class );
		$result->expects( self::never() )->method( 'build' );
		$service = $this->viewService( $this->createMock( ExamReportService::class ), $this->participant(), $result );

		foreach ( array( 0, 3, 999, -1 ) as $row ) {
			self::assertNull( $service->build( $this->report(), $row )['review'], (string) $row );
		}
	}

	public function test_anonymized_member_row_is_placeholder(): void {
		$result = $this->createMock( GuestResultViewService::class );
		$result->expects( self::never() )->method( 'build' );
		$service = $this->viewService( $this->createMock( ExamReportService::class ), $this->participant( '2026-03-20 00:00:00' ), $result );

		$data = $service->build( $this->report(), 1 );

		self::assertSame( ExamReportViewService::PLACEHOLDER, $data['rows'][0]['name'] );
		self::assertFalse( $data['rows'][0]['open'] );
		self::assertNull( $data['review'] );
	}

	public function test_withdrawn_transfer_consent_hides_member(): void {
		$reportService = $this->createMock( ExamReportService::class );
		$reportService->method( 'canInclude' )->willReturn( 'Согласие отозвано.' );
		$result = $this->createMock( GuestResultViewService::class );
		$result->expects( self::never() )->method( 'build' );
		$service = $this->viewService( $reportService, $this->participant(), $result );

		$data = $service->build( $this->report(), 1 );

		self::assertSame( ExamReportViewService::PLACEHOLDER, $data['rows'][0]['name'] );
		self::assertSame( 0, $data['stats']['pending'] );
		self::assertNull( $data['review'] );
	}

	public function test_stats_count_final_results_and_average(): void {
		$service = $this->viewService( $this->createMock( ExamReportService::class ), $this->participant() );

		$data = $service->build( $this->report(), null );

		self::assertSame( 2, $data['stats']['participants'] );
		self::assertSame( '84', $data['stats']['average'] );
		self::assertSame( 'Проверено', $data['rows'][0]['status'] );
	}
}
