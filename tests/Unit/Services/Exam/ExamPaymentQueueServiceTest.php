<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamManualResolutionDTO;
use Inc\DTO\Exam\ExamPaymentLinkDTO;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamManualResolutionRepository;
use Inc\Repositories\WPDBRepositories\ExamParticipantRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Repositories\WPDBRepositories\ExamSessionRepository;
use Inc\Services\Exam\ExamAccessGuard;
use Inc\Services\Exam\ExamPaymentQueueService;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\GuestParticipantMaterializer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExamFixtures;

/**
 * Очередь оплат: видимость по проведению, причина, сеансы для переноса, урегулированные; контактов гостя в данных нет.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamPaymentQueueServiceTest extends TestCase {

	use ExamFixtures;

	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamManualResolutionRepository&MockObject $resolutions;
	private ExamPaymentLinkRepository&MockObject $links;
	private ExamEventRepository&MockObject $events;
	private ExamSessionRepository&MockObject $sessions;
	private ExamAccessGuard&MockObject $guard;
	private ExamPaymentQueueService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->applications = $this->createMock( ExamGuestApplicationRepository::class );
		$this->resolutions  = $this->createMock( ExamManualResolutionRepository::class );
		$this->links        = $this->createMock( ExamPaymentLinkRepository::class );
		$this->events       = $this->createMock( ExamEventRepository::class );
		$this->sessions     = $this->createMock( ExamSessionRepository::class );
		$this->guard        = $this->createMock( ExamAccessGuard::class );
		$participants       = $this->createMock( ExamParticipantRepository::class );
		$materializer       = $this->createMock( GuestParticipantMaterializer::class );
		$materializer->method( 'draftName' )->willReturn( 'Иванов Пётр' );
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'toLocal' )->willReturnArgument( 0 );

		$this->events->method( 'find' )->willReturn( $this->examEvent( array( 'status' => 'published', 'owner_user_id' => '0' ) ) );
		$this->links->method( 'findByApplication' )->willReturn( array( ExamPaymentLinkDTO::fromArray( array(
			'id' => 1, 'application_id' => 9, 'wc_order_id' => 738, 'wc_order_item_id' => 5, 'product_id' => 736, 'amount' => '1500.00', 'currency' => 'RUB',
			'payment_state' => 'paid', 'last_reconciled_at' => '2026-03-10 06:50:00', 'created_at' => '2026-03-10 06:00:00', 'updated_at' => '2026-03-10 06:50:00',
		) ) ) );

		$this->service = new ExamPaymentQueueService( $this->applications, $this->resolutions, $this->links, $this->events, $this->sessions, $participants, $materializer, $this->guard, $time );
	}

	private function application(): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => 'paid_needs_resolution', 'is_held' => '0',
			'request_key' => 'k', 'version' => '2', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00',
		) );
	}

	/** @param array<string, mixed> $override */
	private function session( array $override = array() ): \Inc\DTO\Exam\ExamSessionDTO {
		return $this->examSession( array_merge( array( 'scheduled_at' => '2026-03-12 07:00:00', 'planned_end_at' => '2026-03-12 10:55:00', 'capacity' => '5', 'occupied_count' => '5' ), $override ) );
	}

	public function test_only_events_the_user_may_resolve_are_listed(): void {
		$this->applications->method( 'listByState' )->willReturn( array( $this->application() ) );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->sessions->method( 'findByEvent' )->willReturn( array() );
		$this->guard->method( 'canResolvePayments' )->willReturn( false );

		self::assertSame( array(), $this->service->list( 77, 'needs_help' )['items'] );
	}

	public function test_item_has_order_amount_reason_reconcile_time_and_no_contacts(): void {
		$this->applications->method( 'listByState' )->willReturn( array( $this->application() ) );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->sessions->method( 'findByEvent' )->willReturn( array() );
		$this->guard->method( 'canResolvePayments' )->willReturn( true );

		$item = $this->service->list( 77, 'needs_help' )['items'][0];

		self::assertSame( 'Иванов Пётр', $item['guest'] );
		self::assertSame( 738, $item['order_id'] );
		self::assertSame( '1500.00', $item['amount'] );
		self::assertSame( 'В сеансе не осталось мест.', $item['reason'] );
		self::assertSame( '2026-03-10 06:50:00', $item['last_reconciled'] );
		foreach ( array( 'phone', 'messenger', 'email' ) as $key ) {
			self::assertArrayNotHasKey( $key, $item );
		}
	}

	public function test_reason_follows_session_state(): void {
		$this->applications->method( 'listByState' )->willReturn( array( $this->application() ) );
		$this->sessions->method( 'findByEvent' )->willReturn( array() );
		$this->guard->method( 'canResolvePayments' )->willReturn( true );

		$cases = array(
			array( array( 'status' => 'cancelled' ), 'Сеанс отменён.' ),
			array( array( 'scheduled_at' => '2026-03-10 06:00:00' ), 'Сеанс уже начался.' ),
			array( array( 'occupied_count' => '2' ), 'Не удалось подтвердить запись: проверьте, нет ли у гостя другой записи.' ),
		);
		foreach ( $cases as [ $override, $expected ] ) {
			$sessions = $this->createMock( ExamSessionRepository::class );
			$sessions->method( 'find' )->willReturn( $this->session( $override ) );
			$sessions->method( 'findByEvent' )->willReturn( array() );
			$service = new ExamPaymentQueueService( $this->applications, $this->resolutions, $this->links, $this->events, $sessions, $this->createMock( ExamParticipantRepository::class ), $this->createMock( GuestParticipantMaterializer::class ), $this->guard, $this->timeStub() );

			self::assertSame( $expected, $service->list( 77, 'needs_help' )['items'][0]['reason'] );
		}
	}

	private function timeStub(): ExamTime {
		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:00:00' );
		$time->method( 'toLocal' )->willReturnArgument( 0 );

		return $time;
	}

	public function test_targets_are_open_future_sessions_with_free_seats_of_the_same_event(): void {
		$this->applications->method( 'listByState' )->willReturn( array( $this->application() ) );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->guard->method( 'canResolvePayments' )->willReturn( true );
		$this->sessions->method( 'findByEvent' )->willReturn( array(
			$this->session(),                                                                                              // свой сеанс заявки (id 7)
			$this->session( array( 'id' => '8', 'occupied_count' => '3' ) ),                                               // свободно 2
			$this->session( array( 'id' => '9', 'occupied_count' => '5' ) ),                                               // полный
			$this->session( array( 'id' => '10', 'occupied_count' => '0', 'status' => 'cancelled' ) ),                     // отменён
			$this->session( array( 'id' => '11', 'occupied_count' => '0', 'planned_end_at' => '2026-03-10 06:00:00' ) ),   // закончился
		) );

		$targets = $this->service->list( 77, 'needs_help' )['items'][0]['targets'];

		self::assertSame( array( 8 ), array_column( $targets, 'id' ) );
		self::assertSame( 2, $targets[0]['free'] );
	}

	public function test_resolved_tab_lists_resolutions_with_kind_actor_and_amount(): void {
		$this->resolutions->method( 'listRecentForApplications' )->willReturn( array( ExamManualResolutionDTO::fromArray( array(
			'id' => 1, 'application_id' => 9, 'kind' => 'refunded_outside', 'reason' => 'Вернули в ЮKassa', 'actor_user_id' => 0, 'amount' => '1500.00',
			'old_session_id' => 7, 'created_at' => '2026-03-10 06:55:00',
		) ) ) );
		$this->applications->method( 'find' )->willReturn( $this->application() );
		$this->sessions->method( 'find' )->willReturn( $this->session() );
		$this->guard->method( 'canResolvePayments' )->willReturn( true );

		$item = $this->service->list( 77, 'resolved' )['items'][0];

		self::assertSame( 'Возврат вне системы', $item['kind_label'] );
		self::assertSame( 'Вернули в ЮKassa', $item['reason'] );
		self::assertSame( '1500.00', $item['refund'] );
	}
}
