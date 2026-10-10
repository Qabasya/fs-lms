<?php

declare( strict_types=1 );

namespace Tests\Unit\Services\Exam;

use Inc\DTO\Exam\ExamRegistrationDTO;
use Inc\Repositories\WPDBRepositories\AssessmentAttemptRepository;
use Inc\Repositories\WPDBRepositories\ExamRegistrationRepository;
use Inc\Services\Exam\ExamAttemptService;
use Inc\Services\Exam\ExamEventService;
use Inc\Managers\Wp\TransientManager;
use Inc\Repositories\WPDBRepositories\ExamEventRepository;
use Inc\Repositories\WPDBRepositories\ExamOperationKeyRepository;
use Inc\Repositories\WPDBRepositories\ExamPaymentLinkRepository;
use Inc\Services\Exam\ExamHoldService;
use Inc\Services\Exam\Payment\ExamPaymentReconciler;
use Inc\Services\Exam\Payment\WooGateway;
use Inc\Services\Exam\ExamNotificationComposer;
use Inc\Services\Exam\ExamOutboxWorker;
use Inc\Services\Exam\ExamReminderService;
use Inc\Services\Exam\ExamNoShowService;
use Inc\Services\Exam\ExamTickService;
use Inc\Services\Exam\ExamTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExamTickServiceTest extends TestCase {

	private ExamAttemptService&MockObject $attemptService;
	private ExamNoShowService&MockObject $noShow;
	private AssessmentAttemptRepository&MockObject $attempts;
	private ExamRegistrationRepository&MockObject $registrations;
	private ExamHoldService&MockObject $holds;
	private ExamEventService&MockObject $eventService;
	private ExamReminderService&MockObject $reminders;
	private ExamOutboxWorker&MockObject $worker;
	private ExamOperationKeyRepository&MockObject $operationKeys;
	private WooGateway&MockObject $woo;
	private ExamPaymentReconciler&MockObject $reconciler;
	private ExamPaymentLinkRepository&MockObject $paymentLinks;
	private ExamTickService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->attemptService = $this->createMock( ExamAttemptService::class );
		$this->noShow         = $this->createMock( ExamNoShowService::class );
		$this->attempts       = $this->createMock( AssessmentAttemptRepository::class );
		$this->registrations  = $this->createMock( ExamRegistrationRepository::class );
		$this->holds          = $this->createMock( ExamHoldService::class );
		$this->eventService   = $this->createMock( ExamEventService::class );
		$this->reminders      = $this->createMock( ExamReminderService::class );
		$this->worker         = $this->createMock( ExamOutboxWorker::class );
		$this->operationKeys  = $this->createMock( ExamOperationKeyRepository::class );
		$this->woo            = $this->createMock( WooGateway::class );
		$this->reconciler     = $this->createMock( ExamPaymentReconciler::class );
		$this->paymentLinks   = $this->createMock( ExamPaymentLinkRepository::class );

		$time = $this->createStub( ExamTime::class );
		$time->method( 'nowLocal' )->willReturn( '2026-03-12 14:00:00' );
		$time->method( 'nowUtc' )->willReturn( '2026-03-12 11:00:00' );

		$this->service = new ExamTickService( $this->attemptService, $this->noShow, $this->attempts, $this->registrations, $time, $this->holds, $this->eventService,
			$this->reminders, $this->worker, $this->createMock( ExamNotificationComposer::class ), $this->createMock( ExamEventRepository::class ),
			$this->operationKeys, $this->createMock( TransientManager::class ),
			$this->woo, $this->reconciler, $this->paymentLinks
		);
	}

	private function registration( int $id ): ExamRegistrationDTO {
		return ExamRegistrationDTO::fromArray( array(
			'id' => $id, 'participation_id' => 7, 'session_id' => 100, 'status' => 'confirmed', 'active_slot' => 1, 'created_at' => '2026-03-01 00:00:00',
		) );
	}

	public function test_release_holds_delegates_to_hold_service_with_limit(): void {
		$this->holds->expects( self::once() )->method( 'releaseExpired' )->with( 50 )->willReturn( 3 );

		self::assertSame( 3, $this->service->releaseHolds( 50 ) );
	}

	public function test_auto_expire_selects_overdue_attempts_by_local_time(): void {
		$this->attempts->expects( self::once() )->method( 'listOverdueExamIds' )->with( '2026-03-12 14:00:00', 200 )->willReturn( array() );

		self::assertSame( 0, $this->service->autoExpire() );
	}

	public function test_auto_expire_continues_after_single_failure(): void {
		$this->attempts->method( 'listOverdueExamIds' )->willReturn( array( 1, 2, 3 ) );
		$this->attemptService->method( 'finalizeExpired' )->willReturnCallback( static function ( int $id ): bool {
			if ( 2 === $id ) {
				throw new \RuntimeException( 'сбой на одной попытке' );
			}
			return true;
		} );

		self::assertSame( 2, $this->service->autoExpire(), 'Ошибка попытки 2 не останавливает остальные.' );
	}

	public function test_auto_expire_does_not_count_attempts_already_finished(): void {
		$this->attempts->method( 'listOverdueExamIds' )->willReturn( array( 1, 2 ) );
		$this->attemptService->method( 'finalizeExpired' )->willReturnOnConsecutiveCalls( true, false );

		self::assertSame( 1, $this->service->autoExpire() );
	}

	public function test_sweep_marks_missed_and_continues_after_failure(): void {
		$this->registrations->expects( self::once() )->method( 'listActiveOfEndedSessions' )->with( '2026-03-12 11:00:00', 200 )
			->willReturn( array( $this->registration( 10 ), $this->registration( 11 ), $this->registration( 12 ) ) );
		$this->noShow->method( 'markMissed' )->willReturnCallback( static function ( int $id ): bool {
			if ( 11 === $id ) {
				throw new \RuntimeException( 'сбой на одной записи' );
			}
			return true;
		} );

		self::assertSame( 2, $this->service->sweep() );
	}

	public function test_minute_tick_runs_auto_expire_before_no_show_sweep(): void {
		$order = array();
		$this->attempts->method( 'listOverdueExamIds' )->willReturnCallback( static function () use ( &$order ): array {
			$order[] = 'expire';
			return array();
		} );
		$this->registrations->method( 'listActiveOfEndedSessions' )->willReturnCallback( static function () use ( &$order ): array {
			$order[] = 'sweep';
			return array();
		} );

		$this->eventService->method( 'completionCandidates' )->willReturnCallback( static function () use ( &$order ): array {
			$order[] = 'complete';
			return array();
		} );
		$this->reminders->method( 'soon' )->willReturnCallback( static function () use ( &$order ): int {
			$order[] = 'reminders';
			return 2;
		} );

		$result = $this->service->autoExpireTick();

		self::assertSame( array( 'expire', 'sweep', 'complete', 'reminders' ), $order );
		self::assertSame( array( 'expired' => 0, 'missed' => 0, 'completed' => 0, 'reminders' => 2 ), $result );
	}

	public function test_auto_expire_tick_runs_steps_in_order(): void {
		$this->test_minute_tick_runs_auto_expire_before_no_show_sweep();
	}

	public function test_step_failure_does_not_stop_next_steps(): void {
		$this->attempts->method( 'listOverdueExamIds' )->willThrowException( new \RuntimeException( 'db' ) );
		$this->registrations->method( 'listActiveOfEndedSessions' )->willReturn( array() );
		$this->reminders->expects( self::once() )->method( 'soon' )->willReturn( 1 );

		$result = $this->service->autoExpireTick();

		self::assertSame( 0, $result['expired'] );
		self::assertSame( 1, $result['reminders'] );
	}

	public function test_hold_release_tick_runs_steps_in_order(): void {
		$order = array();
		$this->holds->method( 'releaseExpired' )->willReturnCallback( static function () use ( &$order ): int {
			$order[] = 'holds';
			return 2;
		} );
		$this->operationKeys->method( 'purgeExpired' )->with( '2026-03-12 11:00:00' )->willReturnCallback( static function () use ( &$order ): int {
			$order[] = 'purge';
			return 0;
		} );

		self::assertSame( 2, $this->service->releaseHolds() );
		self::assertSame( array( 'holds', 'purge' ), $order );
	}

	public function test_deliver_events_delegates_to_worker(): void {
		$this->worker->expects( self::once() )->method( 'run' )->with( 50 )->willReturn( 4 );

		self::assertSame( 4, $this->service->deliverEvents( 50 ) );
	}

	public function test_complete_events_continues_after_failure_and_counts_completed(): void {
		$this->eventService->method( 'completionCandidates' )->willReturn( array( 1, 2, 3 ) );
		$this->eventService->method( 'completeIfDone' )->willReturnCallback( static function ( int $id ): bool {
			if ( 2 === $id ) {
				throw new \RuntimeException( 'boom' );
			}
			return true;
		} );

		self::assertSame( 2, $this->service->completeEvents() );
	}

	private function link( int $orderId ): \Inc\DTO\Exam\ExamPaymentLinkDTO {
		return \Inc\DTO\Exam\ExamPaymentLinkDTO::fromArray( array(
			'id' => $orderId, 'application_id' => 9, 'wc_order_id' => $orderId, 'wc_order_item_id' => $orderId, 'product_id' => 500, 'amount' => '500', 'currency' => 'RUB',
			'payment_state' => 'pending', 'created_at' => '2026-03-12 10:00:00', 'updated_at' => '2026-03-12 10:00:00',
		) );
	}

	public function test_reconcile_runs_before_hold_release(): void {
		$order = array();
		$this->woo->method( 'isActive' )->willReturn( true );
		$this->paymentLinks->method( 'listPendingForReconcile' )->willReturn( array( $this->link( 77 ) ) );
		$this->reconciler->method( 'reconcileOrder' )->willReturnCallback( static function () use ( &$order ): void {
			$order[] = 'reconcile';
		} );
		$this->holds->method( 'releaseExpired' )->willReturnCallback( static function () use ( &$order ): int {
			$order[] = 'release';
			return 0;
		} );

		$this->service->releaseHolds();

		self::assertSame( array( 'reconcile', 'release' ), $order );
	}

	public function test_reconcile_skips_when_woo_inactive(): void {
		$this->woo->method( 'isActive' )->willReturn( false );
		$this->paymentLinks->expects( self::never() )->method( 'listPendingForReconcile' );

		$this->service->reconcilePayments();
	}

	public function test_reconcile_continues_after_single_failure(): void {
		$this->woo->method( 'isActive' )->willReturn( true );
		$this->paymentLinks->method( 'listPendingForReconcile' )->willReturn( array( $this->link( 1 ), $this->link( 2 ), $this->link( 2 ), $this->link( 3 ) ) );
		$seen = array();
		$this->reconciler->method( 'reconcileOrder' )->willReturnCallback( static function ( int $id ) use ( &$seen ): void {
			$seen[] = $id;
			if ( 2 === $id ) {
				throw new \RuntimeException( 'boom' );
			}
		} );

		$this->service->reconcilePayments();

		self::assertSame( array( 1, 2, 3 ), $seen, 'Заказ сверяется один раз, ошибка заказа 2 не останавливает 3.' );
	}

	public function test_recheck_of_waiting_for_help_runs_between_reconcile_and_release(): void {
		$order = array();
		$this->woo->method( 'isActive' )->willReturn( true );
		$this->paymentLinks->method( 'listPendingForReconcile' )->willReturnCallback( static function () use ( &$order ): array {
			$order[] = 'reconcile';
			return array();
		} );
		$this->reconciler->method( 'refreshWaitingForHelp' )->willReturnCallback( static function () use ( &$order ): int {
			$order[] = 'recheck';
			return 0;
		} );
		$this->holds->method( 'releaseExpired' )->willReturnCallback( static function () use ( &$order ): int {
			$order[] = 'release';
			return 0;
		} );

		$this->service->releaseHolds();

		self::assertSame( array( 'reconcile', 'recheck', 'release' ), $order );
	}
}
