<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdFullSyncService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdReconcileService;
use PHPUnit\Framework\TestCase;

class AdFullSyncServiceTest extends TestCase {
	private AdSyncConfig $config;
	private AdOutboxRepository $outbox;
	private AdProvisioningService $provisioning;
	private AdDeliveryService $delivery;
	private AdReconcileService $reconcile;
	private AdSyncStateRepository $state;
	private array $items = array();

	protected function setUp(): void {
		$this->config = $this->createMock( AdSyncConfig::class );
		$this->config->method( 'excludedUsernames' )->willReturn( array( 'test' ) );
		$this->config->method( 'reconcileApply' )->willReturn( true );
		$this->outbox = $this->createMock( AdOutboxRepository::class );
		$this->outbox->method( 'enqueue' )->willReturnCallback( function ( array $data ): int {
			$id = count( $this->items ) + 1;
			$this->items[$id] = new AdOutboxItemDTO( $id, $data['event'], $data['application_id'] ?? null, $data['person_id'] ?? null, $data['target'], $data['idempotency_key'], 'pending', 0, null, null, '2026-10-16', null );
			return $id;
		} );
		$this->outbox->method( 'find' )->willReturnCallback( fn( int $id ): ?AdOutboxItemDTO => $this->items[$id] ?? null );
		$this->provisioning = $this->createMock( AdProvisioningService::class );
		$this->delivery = $this->createMock( AdDeliveryService::class );
		$this->delivery->method( 'checkConnection' )->willReturn( array( 'ok' => true, 'message' => 'ok' ) );
		$this->reconcile = $this->createMock( AdReconcileService::class );
		$this->reconcile->method( 'activeUsernames' )->willReturn( array( 'alice' ) );
		$this->state = new class extends AdSyncStateRepository {
			public array $data = array();
			public function get(): array { return $this->data; }
			public function update( array $partial ): void { $this->data = array_merge( $this->data, $partial ); }
			public function acquireLock(): bool { return true; }
			public function releaseLock(): void {}
		};
	}

	private function service(): AdFullSyncService {
		return new AdFullSyncService( $this->config, $this->provisioning, $this->outbox, $this->state, $this->delivery, $this->reconcile );
	}

	private function plan( int $count = 1 ): array {
		$targets = array();
		for ( $i = 1; $i <= $count; ++$i ) {
			$targets['student' . $i] = array( 'event' => 'provision', 'person_id' => $i, 'target' => 'student' . $i );
		}
		return array( 'targets' => $targets, 'issues' => array() );
	}

	private function complete( array $ids, bool $failFirst = false ): array {
		foreach ( $ids as $index => $id ) {
			$changes = array( 'status' => $failFirst && 0 === $index ? 'failed' : 'sent', 'lastError' => $failFirst && 0 === $index ? 'Учётка вне управляемой области' : null );
			$this->items[$id] = new AdOutboxItemDTO( ...array_replace( get_object_vars( $this->items[$id] ), $changes ) );
		}
		return array( 'sent' => count( $ids ), 'failed' => 0, 'dead' => 0, 'skipped' => '' );
	}

	public function test_run_resumes_and_delivers_in_bounded_batches_then_reconciles(): void {
		$this->provisioning->expects( self::once() )->method( 'fullSyncTargets' )->willReturn( $this->plan( 7 ) );
		$this->delivery->expects( self::exactly( 3 ) )->method( 'deliverSelected' )->willReturnCallback( function ( array $ids ): array {
			self::assertLessThanOrEqual( 3, count( $ids ) );
			return $this->complete( $ids );
		} );
		$this->delivery->expects( self::once() )->method( 'reconcile' )->with( array( 'alice' ), true )->willReturn( array( 'ok' => true, 'message' => 'Отключено учёток: 0.', 'usernames' => array(), 'applied' => true ) );
		$s = $this->service();
		$run = $s->start();
		self::assertSame( 8, $run['total'] );
		self::assertSame( $run['run_id'], $s->start()['run_id'] );
		self::assertTrue( $this->items[8]->isExclusionDeletion() );
		self::assertSame( 'test', $this->items[8]->target );
		for ( $i = 0; $i < 3; ++$i ) { $progress = $s->step( $run['run_id'] ); }
		self::assertSame( 8, $progress['processed'] );
		$result = $s->finish( $run['run_id'] );
		self::assertTrue( $result['ok'] );
		self::assertSame( array_fill( 0, 8, 'ok' ), array_column( $result['rows'], 'status' ) );
		self::assertStringNotContainsString( 'password', json_encode( $this->state->get() ) );
	}

	public function test_errors_are_reported_and_prevent_disabling_extra_accounts(): void {
		$this->provisioning->method( 'fullSyncTargets' )->willReturn( $this->plan() );
		$this->delivery->method( 'deliverSelected' )->willReturnCallback( fn( array $ids ): array => $this->complete( $ids, true ) );
		$this->delivery->expects( self::once() )->method( 'reconcile' )->with( array( 'alice' ), false )->willReturn( array( 'ok' => true, 'message' => 'Только журнал: 1.', 'usernames' => array( 'extra' ), 'applied' => false ) );
		$s = $this->service(); $run = $s->start(); $s->step( $run['run_id'] ); $result = $s->finish( $run['run_id'] );
		self::assertFalse( $result['ok'] );
		self::assertSame( 'Учётка вне управляемой области', $result['rows'][0]['message'] );
		self::assertSame( 'warning', $result['rows'][2]['status'] );
		self::assertStringContainsString( 'Отключение лишних не выполнялось', $result['message'] );
	}

	public function test_offline_batch_keeps_cursor_for_a_later_resume(): void {
		$this->provisioning->method( 'fullSyncTargets' )->willReturn( $this->plan() );
		$this->delivery->method( 'deliverSelected' )->willReturn( array( 'sent' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => 'Сервер недоступен' ) );
		$s = $this->service(); $run = $s->start();
		self::assertSame( 0, $s->step( $run['run_id'] )['processed'] );
		self::assertSame( $run['run_id'], $s->start()['run_id'] );
	}

	public function test_finish_does_not_reconcile_an_incomplete_run(): void {
		$this->provisioning->method( 'fullSyncTargets' )->willReturn( $this->plan() );
		$this->delivery->expects( self::never() )->method( 'reconcile' );
		$s = $this->service(); $run = $s->start();
		$this->expectException( \RuntimeException::class );
		$s->finish( $run['run_id'] );
	}

	public function test_unknown_run_is_refused(): void {
		$this->delivery->expects( self::never() )->method( 'deliverSelected' );
		$this->expectException( \RuntimeException::class );
		$this->service()->step( 'unknown' );
	}
}
