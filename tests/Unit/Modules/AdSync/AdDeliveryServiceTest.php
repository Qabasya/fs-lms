<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\DTO\AdServerResponseDTO;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;
use Inc\Modules\AdSync\Services\AdAuditLogger;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdServerClient;
use PHPUnit\Framework\TestCase;

/**
 * Доставка в офис: судьба задания по ответу сервера, пауза при простое, блокировка.
 */
class AdDeliveryServiceTest extends TestCase {

	private AdOutboxRepository $outbox;
	private AdProvisioningService $provisioning;
	private AdServerClient $client;
	private AdSyncStateRepository $state;
	private AdAuditLogger $audit;

	protected function setUp(): void {
		$this->outbox       = $this->createMock( AdOutboxRepository::class );
		$this->provisioning = $this->createMock( AdProvisioningService::class );
		$this->client       = $this->createMock( AdServerClient::class );
		$this->state        = $this->createMock( AdSyncStateRepository::class );
		$this->audit        = $this->createMock( AdAuditLogger::class );

		$this->client->method( 'notReadyReason' )->willReturn( '' );
		$this->state->method( 'acquireLock' )->willReturn( true );
		$this->provisioning->method( 'payloadFor' )->willReturnCallback(
			fn( AdOutboxItemDTO $row ): array => array( 'event' => $row->event, 'idempotency_key' => $row->idempotencyKey )
		);
	}

	private function service(): AdDeliveryService {
		return new AdDeliveryService( $this->outbox, $this->provisioning, $this->client, $this->state, $this->audit );
	}

	private function row( int $id ): AdOutboxItemDTO {
		return new AdOutboxItemDTO(
			id: $id, event: 'provision', applicationId: $id, personId: null, target: null,
			idempotencyKey: 'app:' . $id, status: 'pending', attempts: 0, nextAttemptAt: null,
			lastError: null, createdAt: '2026-01-01 00:00:00', sentAt: null
		);
	}

	// ── classify ─────────────────────────────────────────────────────────────

	public function test_classify(): void {
		$cases = array(
			array( new AdServerResponseDTO( 200, array( 'status' => 'done' ) ), AdDeliveryService::DONE ),
			array( new AdServerResponseDTO( 200, array( 'status' => 'failed', 'error' => 'exists' ) ), AdDeliveryService::FAILED ),
			array( new AdServerResponseDTO( 422, array( 'error' => 'bad username' ) ), AdDeliveryService::FAILED ),
			array( new AdServerResponseDTO( 200, null ), AdDeliveryService::FAILED ),
			array( new AdServerResponseDTO( 0, null, 'timeout' ), AdDeliveryService::UNAVAILABLE ),
			array( new AdServerResponseDTO( 503, null ), AdDeliveryService::UNAVAILABLE ),
			array( new AdServerResponseDTO( 401, array( 'error' => 'bad signature' ) ), AdDeliveryService::UNAVAILABLE ),
		);
		foreach ( $cases as [ $response, $expected ] ) {
			self::assertSame( $expected, AdDeliveryService::classify( $response ), $response->describe() );
		}
	}

	// ── deliverPending ───────────────────────────────────────────────────────

	public function test_done_marks_sent_and_failed_spends_attempt(): void {
		$this->outbox->method( 'listPending' )->willReturn( array( $this->row( 1 ), $this->row( 2 ) ) );
		$this->client->method( 'request' )->willReturnOnConsecutiveCalls(
			new AdServerResponseDTO( 200, array( 'status' => 'done', 'outcome' => 'created' ) ),
			new AdServerResponseDTO( 200, array( 'status' => 'failed', 'error' => 'OU not found' ) )
		);

		$this->outbox->expects( self::once() )->method( 'markSent' )->with( 1 );
		$this->outbox->expects( self::once() )->method( 'markFailed' )->with( 2, 'HTTP 200: OU not found' )->willReturn( false );
		// В журнал — выполненное; промежуточная ошибка (попытки ещё есть) — нет.
		$this->audit->expects( self::once() )->method( 'done' )->with( self::anything(), 'created' );
		$this->audit->expects( self::never() )->method( 'dead' );
		$this->state->expects( self::once() )->method( 'releaseLock' );

		$report = $this->service()->deliverPending();

		self::assertSame( array( 1, 1 ), array( $report['sent'], $report['failed'] ) );
	}

	public function test_unreachable_pauses_delivery_without_spending_attempts(): void {
		$this->outbox->method( 'listPending' )->willReturn( array( $this->row( 1 ), $this->row( 2 ) ) );
		$this->client->expects( self::once() )->method( 'request' )->willReturn( new AdServerResponseDTO( 0, null, 'Connection refused' ) );

		$this->outbox->expects( self::never() )->method( 'markFailed' );
		$this->outbox->expects( self::never() )->method( 'markSent' );
		$this->state->expects( self::once() )->method( 'markUnreachable' );

		$report = $this->service()->deliverPending();

		self::assertStringContainsString( 'Connection refused', $report['skipped'] );
	}

	public function test_job_without_data_goes_dead_without_request(): void {
		$this->provisioning = $this->createMock( AdProvisioningService::class );
		$this->provisioning->method( 'payloadFor' )->willReturn( null );
		$this->outbox->method( 'listPending' )->willReturn( array( $this->row( 3 ) ) );

		$this->client->expects( self::never() )->method( 'request' );
		$this->outbox->expects( self::once() )->method( 'markDead' )->with( 3 );
		$this->audit->expects( self::once() )->method( 'dead' );

		self::assertSame( 1, $this->service()->deliverPending()['dead'] );
	}

	public function test_last_failed_attempt_is_logged_with_server_error(): void {
		$this->outbox->method( 'listPending' )->willReturn( array( $this->row( 4 ) ) );
		$this->client->method( 'request' )->willReturn(
			new AdServerResponseDTO( 200, array( 'status' => 'failed', 'error' => 'учётная запись вне управляемой зоны' ) )
		);
		$this->outbox->method( 'markFailed' )->willReturn( true );

		$this->audit->expects( self::once() )->method( 'dead' )->with( self::anything(), 'учётная запись вне управляемой зоны' );

		$this->service()->deliverPending();
	}

	public function test_paused_delivery_is_skipped_unless_forced(): void {
		$this->state->method( 'pausedUntil' )->willReturn( time() + 300 );
		$this->outbox->method( 'listPending' )->willReturn( array() );

		$this->outbox->expects( self::once() )->method( 'listPending' );

		self::assertNotSame( '', $this->service()->deliverPending()['skipped'] );
		$this->service()->deliverPending( 20, true );
	}

	public function test_second_process_does_not_deliver(): void {
		$this->state = $this->createMock( AdSyncStateRepository::class );
		$this->state->method( 'acquireLock' )->willReturn( false );

		$this->outbox->expects( self::never() )->method( 'listPending' );

		self::assertSame( 'Доставка уже идёт в другом процессе.', $this->service()->deliverPending()['skipped'] );
	}

	public function test_not_configured_transport_skips_delivery(): void {
		$this->client = $this->createMock( AdServerClient::class );
		$this->client->method( 'notReadyReason' )->willReturn( 'Не указан адрес сервера в офисе.' );

		$this->outbox->expects( self::never() )->method( 'listPending' );

		self::assertSame( 'Не указан адрес сервера в офисе.', $this->service()->deliverPending()['skipped'] );
	}

	// ── reconcile ────────────────────────────────────────────────────────────

	public function test_reconcile_reports_dry_run_count(): void {
		$this->client->method( 'request' )->willReturn(
			new AdServerResponseDTO( 200, array( 'status' => 'ok', 'applied' => false, 'disabled' => array( 'a', 'b' ) ) )
		);

		self::assertSame(
			array( 'ok' => true, 'message' => 'Отключил бы (только журнал): 2.' ),
			$this->service()->reconcile( array( 'x' ), false )
		);
	}

	public function test_reconcile_abort_by_server_guard_is_not_ok(): void {
		$this->client->method( 'request' )->willReturn(
			new AdServerResponseDTO( 200, array( 'status' => 'aborted', 'disabled' => array(), 'abort_reason' => 'к отключению 12 учёток, порог 10' ) )
		);

		$result = $this->service()->reconcile( array( 'x' ), true );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'Сверка отменена сервером: к отключению 12 учёток, порог 10', $result['message'] );
	}
}
