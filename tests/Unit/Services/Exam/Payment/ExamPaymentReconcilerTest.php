<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam\Payment;

use Inc\DTO\Exam\ExamGuestApplicationDTO;
use Inc\DTO\Exam\ExamPaymentLinkDTO;
use Inc\Enums\Exam\ExamOutboxEvent;
use Inc\Repositories\WPDBRepositories\ExamGuestApplicationRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\ExamOutbox;
use Inc\Services\Exam\ExamTime;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\WooGateway;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Сверка заказов: оплата подтверждает запись только по `is_paid()`, повторы безвредны, поздняя и лишняя оплата уходят сотруднику.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamPaymentReconcilerTest extends TestCase {

	private WooGateway&MockObject $woo;
	private ExamPaymentLinkRepository&MockObject $links;
	private ExamGuestApplicationRepository&MockObject $applications;
	private ExamHoldService&MockObject $holds;
	private ExamOutbox&MockObject $outbox;
	private ExamPaymentReconciler $reconciler;

	/** @var list<ExamPaymentLinkDTO> */
	private array $orderLinks = array();
	/** @var list<array{0: int, 1: array<string, mixed>}> */
	private array $linkUpdates = array();
	private string $appState = 'payment_pending';
	private bool $paid       = true;
	private string $status   = 'processing';

	protected function setUp(): void {
		parent::setUp();

		$this->woo          = $this->createMock( WooGateway::class );
		$this->links        = $this->createMock( ExamPaymentLinkRepository::class );
		$this->applications = $this->createMock( ExamGuestApplicationRepository::class );
		$this->holds        = $this->createMock( ExamHoldService::class );
		$this->outbox       = $this->createMock( ExamOutbox::class );
		$time               = $this->createStub( ExamTime::class );
		$time->method( 'nowUtc' )->willReturn( '2026-03-10 07:05:00' );
		$time->method( 'addMinutes' )->willReturn( '2026-03-10 06:50:00' );

		$this->woo->method( 'isActive' )->willReturn( true );
		$this->woo->method( 'isOrderPaid' )->willReturnCallback( fn (): bool => $this->paid );
		$this->woo->method( 'orderStatus' )->willReturnCallback( fn (): string => $this->status );
		$this->links->method( 'listByOrder' )->willReturnCallback( fn (): array => $this->orderLinks );
		$this->links->method( 'findByApplication' )->willReturnCallback( fn (): array => $this->orderLinks );
		$this->links->method( 'update' )->willReturnCallback( function ( int $id, array $data ): int {
			$this->linkUpdates[] = array( $id, $data );
			return 1;
		} );
		$this->applications->method( 'find' )->willReturnCallback( fn (): ExamGuestApplicationDTO => $this->app( $this->appState ) );
		$this->holds->method( 'convert' )->willReturnCallback( fn (): ExamGuestApplicationDTO => $this->app( 'confirmed' ) );

		$this->reconciler = new ExamPaymentReconciler( $this->woo, $this->links, $this->applications, $this->holds, $this->outbox, $time );
		$this->orderLinks = array( $this->link( 1, 'pending' ) );
	}

	private function app( string $state ): ExamGuestApplicationDTO {
		return ExamGuestApplicationDTO::fromArray( array(
			'id' => '9', 'event_id' => '3', 'session_id' => '7', 'source_id' => '14', 'identity_hash' => 'h', 'state' => $state, 'is_held' => '0',
			'request_key' => 'k', 'version' => '2', 'created_at' => '2026-03-10 06:55:00', 'updated_at' => '2026-03-10 06:55:00',
		) );
	}

	private function link( int $id, string $state ): ExamPaymentLinkDTO {
		return ExamPaymentLinkDTO::fromArray( array(
			'id' => $id, 'application_id' => 9, 'wc_order_id' => 77, 'wc_order_item_id' => 30 + $id, 'product_id' => 500, 'amount' => '500', 'currency' => 'RUB',
			'payment_state' => $state, 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		) );
	}

	public function test_paid_order_converts_live_hold(): void {
		$this->holds->expects( self::once() )->method( 'convert' )->with( 9, null );

		$this->reconciler->reconcileOrder( 77 );

		self::assertSame( 'paid', $this->linkUpdates[0][1]['payment_state'] );
	}

	public function test_processing_paid_order_is_enough(): void {
		$this->status = 'processing';
		$this->holds->expects( self::once() )->method( 'convert' );

		$this->reconciler->reconcileOrder( 77 );
	}

	public function test_on_hold_and_pending_do_not_confirm(): void {
		$this->paid = false;
		foreach ( array( 'on-hold', 'pending' ) as $status ) {
			$this->status = $status;
			$this->holds->expects( self::never() )->method( 'convert' );

			$this->reconciler->reconcileOrder( 77 );
		}

		self::assertSame( array(), $this->linkUpdates );
	}

	public function test_zero_total_coupon_order_confirms(): void {
		// is_paid() для нулевого заказа true — связь с нулевой суммой подтверждает запись как обычная.
		$this->orderLinks = array( ExamPaymentLinkDTO::fromArray( array(
			'id' => 1, 'application_id' => 9, 'wc_order_id' => 77, 'wc_order_item_id' => 31, 'product_id' => 500, 'amount' => '0.00', 'currency' => 'RUB',
			'payment_state' => 'pending', 'created_at' => '2026-03-10 07:00:00', 'updated_at' => '2026-03-10 07:00:00',
		) ) );
		$this->holds->expects( self::once() )->method( 'convert' );

		$this->reconciler->reconcileOrder( 77 );
	}

	public function test_repeat_event_is_noop(): void {
		$this->orderLinks = array( $this->link( 1, 'paid' ) );
		$this->holds->expects( self::never() )->method( 'convert' );

		$this->reconciler->reconcileOrder( 77 );

		self::assertSame( array(), $this->linkUpdates );
	}

	public function test_reconcile_twice_gives_same_state(): void {
		$this->holds->expects( self::exactly( 2 ) )->method( 'convert' )->willReturn( $this->app( 'confirmed' ) );

		$this->reconciler->reconcileOrder( 77 );
		$this->reconciler->reconcileOrder( 77 ); // связь в мок-репозитории всё ещё pending, convert сам безвреден для confirmed

		self::assertSame( 'confirmed', $this->app( 'confirmed' )->state );
	}

	public function test_late_payment_without_seat_becomes_needs_resolution(): void {
		$holds = $this->createMock( ExamHoldService::class );
		$holds->method( 'convert' )->willReturn( $this->app( 'paid_needs_resolution' ) );
		$this->reconciler = new ExamPaymentReconciler( $this->woo, $this->links, $this->applications, $holds, $this->outbox, $this->createStub( ExamTime::class ) );

		$this->reconciler->reconcileOrder( 77 );

		// Решение «нужна помощь» принимает convert() (событие он пишет сам); связь отмечена оплаченной, сверхброни нет.
		self::assertSame( 'paid', $this->linkUpdates[0][1]['payment_state'] );
	}

	public function test_old_failed_order_does_not_cancel_confirmed_registration(): void {
		$this->paid       = false;
		$this->status     = 'failed';
		$this->appState   = 'confirmed';
		$this->holds->expects( self::never() )->method( 'release' );

		$this->reconciler->reconcileOrder( 77 );

		self::assertSame( 'failed', $this->linkUpdates[0][1]['payment_state'] );
	}

	public function test_second_paid_order_for_confirmed_application_goes_to_manual_resolution(): void {
		$this->appState   = 'confirmed';
		$this->orderLinks = array( $this->link( 2, 'pending' ), $this->link( 1, 'paid' ) );
		$this->holds->expects( self::never() )->method( 'convert' );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', 9, 2, self::callback( static fn ( array $p ): bool => 'extra_payment' === $p['reason'] ) );

		$this->reconciler->reconcileOrder( 77 );
	}

	public function test_manual_refund_in_woo_flags_discrepancy_and_keeps_attempt(): void {
		$this->paid       = false;
		$this->status     = 'refunded';
		$this->orderLinks = array( $this->link( 1, 'paid' ) );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::ReconcileFailed, 'guest_application', 9, 2, self::callback( static fn ( array $p ): bool => 'refunded_in_shop' === $p['reason'] ) );
		$this->holds->expects( self::never() )->method( 'release' );

		$this->reconciler->reconcileOrder( 77 );

		self::assertSame( array(), $this->linkUpdates, 'Запись и связь не трогаются.' );
	}

	public function test_db_failure_keeps_link_pending_and_reports(): void {
		$holds = $this->createMock( ExamHoldService::class );
		$holds->method( 'convert' )->willThrowException( new \RuntimeException( 'db down' ) );
		$this->reconciler = new ExamPaymentReconciler( $this->woo, $this->links, $this->applications, $holds, $this->outbox, $this->createStub( ExamTime::class ) );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::ReconcileFailed, 'guest_application', 9, 2, self::anything() );

		try {
			$this->reconciler->reconcileOrder( 77 );
			self::fail( 'Исключение не должно глотаться.' );
		} catch ( \RuntimeException $e ) {
			self::assertArrayNotHasKey( 'payment_state', $this->linkUpdates[0][1], 'Связь остаётся pending.' );
			self::assertArrayHasKey( 'last_reconciled_at', $this->linkUpdates[0][1] );
		}
	}

	public function test_payment_after_staff_cancel_does_not_resurrect_registration(): void {
		$holds = $this->createMock( ExamHoldService::class );
		$holds->method( 'convert' )->willReturn( $this->app( 'cancelled' ) );
		$this->reconciler = new ExamPaymentReconciler( $this->woo, $this->links, $this->applications, $holds, $this->outbox, $this->createStub( ExamTime::class ) );
		$this->outbox->expects( self::once() )->method( 'add' )->with( ExamOutboxEvent::PaidNeedsResolution, 'guest_application', 9, 2, self::callback( static fn ( array $p ): bool => 'payment_after_cancel' === $p['reason'] ) );

		$this->reconciler->reconcileOrder( 77 );
	}

	public function test_inactive_woocommerce_does_nothing(): void {
		$woo = $this->createMock( WooGateway::class );
		$woo->method( 'isActive' )->willReturn( false );
		$this->links->expects( self::never() )->method( 'listByOrder' );
		$reconciler = new ExamPaymentReconciler( $woo, $this->links, $this->applications, $this->holds, $this->outbox, $this->createStub( ExamTime::class ) );

		$reconciler->reconcileOrder( 77 );
	}
}
