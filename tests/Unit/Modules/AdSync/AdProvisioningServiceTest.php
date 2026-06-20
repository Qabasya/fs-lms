<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\Person\UserManager;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Services\Security\PiiCryptoService;
use PHPUnit\Framework\TestCase;

class AdProvisioningServiceTest extends TestCase {

	private function app(): ApplicationDTO {
		return ApplicationDTO::fromArray( array(
			'id'               => 5,
			'status'           => 'pending_parent',
			'created_at'       => '2026-01-01 00:00:00',
			'updated_at'       => '2026-01-01 00:00:00',
			'student_data_enc' => 'ENC',
			'subject_key'      => 'inf',
		) );
	}

	private function blobJson(): string {
		return (string) json_encode( array(
			'username'       => 'i.petrov',
			'login_password' => 'Secret123',
			'first_name'     => 'Иван',
			'last_name'      => 'Петров',
			'email'          => 'i@example.com',
		) );
	}

	private function row( string $event, array $o = array() ): AdOutboxItemDTO {
		return new AdOutboxItemDTO(
			id: $o['id'] ?? 7, event: $event,
			applicationId: $o['app'] ?? null, personId: $o['person'] ?? null,
			target: $o['target'] ?? null, idempotencyKey: $o['idem'] ?? 'k',
			status: $o['status'] ?? 'pending', attempts: 0,
			nextAttemptAt: null, lastError: null, createdAt: '2026-01-01 00:00:00', sentAt: null
		);
	}

	/** @return array<string, mixed> */
	private function mocks(): array {
		return array(
			'outbox'  => $this->createMock( AdOutboxRepository::class ),
			'apps'    => $this->createMock( ApplicationRepository::class ),
			'crypto'  => $this->createMock( PiiCryptoService::class ),
			'persons' => $this->createMock( PersonRepository::class ),
			'users'   => $this->createMock( UserManager::class ),
		);
	}

	private function service( array $m ): AdProvisioningService {
		return new AdProvisioningService(
			$m['outbox'], $m['apps'], $m['crypto'], $m['persons'], $m['users']
		);
	}

	// ── provision ────────────────────────────────────────────────────────────

	public function test_enqueue_provision_is_pii_free(): void {
		$enqueued = null;
		$m = $this->mocks();
		$m['apps']->method( 'find' )->willReturn( $this->app() );
		$m['outbox']->method( 'enqueue' )->willReturnCallback( function ( array $d ) use ( &$enqueued ) {
			$enqueued = $d;
			return 7;
		} );

		$this->service( $m )->enqueueProvision( 5 );

		self::assertSame( 'provision', $enqueued['event'] );
		self::assertSame( 5, $enqueued['application_id'] );
		self::assertSame( 'app:5', $enqueued['idempotency_key'] );
		self::assertStringNotContainsString( 'secret123', strtolower( (string) json_encode( $enqueued ) ) );
	}

	public function test_enqueue_provision_skips_when_application_missing(): void {
		$m = $this->mocks();
		$m['apps']->method( 'find' )->willReturn( null );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );
		$this->service( $m )->enqueueProvision( 999 );
	}

	public function test_pending_provision_job_has_minimal_fields(): void {
		$m = $this->mocks();
		$m['apps']->method( 'find' )->willReturn( $this->app() );
		$m['crypto']->method( 'decrypt' )->willReturn( $this->blobJson() );
		$m['outbox']->method( 'listPending' )->willReturn( array( $this->row( 'provision', array( 'app' => 5, 'idem' => 'app:5' ) ) ) );

		$job = $this->service( $m )->pendingJobs()[0];

		self::assertSame( 'i.petrov', $job['username'] );
		self::assertSame( 'Secret123', $job['password'] );
		self::assertSame( 'Иван', $job['first'] );
		self::assertSame( 'Петров', $job['last'] );
		self::assertSame( 'inf', $job['subject_key'] );
		// Убранные поля:
		self::assertArrayNotHasKey( 'email', $job );
		self::assertArrayNotHasKey( 'subject_name', $job );
		self::assertArrayNotHasKey( 'ttl_days', $job );
		self::assertArrayNotHasKey( 'group_dn', $job );
	}

	// ── deprovision ──────────────────────────────────────────────────────────

	public function test_enqueue_deprovision_stores_username_in_target_without_password(): void {
		$enqueued = null;
		$m = $this->mocks();
		$m['apps']->method( 'find' )->willReturn( $this->app() );
		$m['crypto']->method( 'decrypt' )->willReturn( $this->blobJson() );
		$m['outbox']->method( 'enqueue' )->willReturnCallback( function ( array $d ) use ( &$enqueued ) {
			$enqueued = $d;
			return 8;
		} );

		$this->service( $m )->enqueueDeprovisionByApplication( 5 );

		self::assertSame( 'deprovision', $enqueued['event'] );
		self::assertSame( 'i.petrov', $enqueued['target'] );
		self::assertSame( 'deprovision:app:5', $enqueued['idempotency_key'] );
		self::assertStringNotContainsString( 'secret123', strtolower( (string) json_encode( $enqueued ) ) );
	}

	public function test_pending_deprovision_job_has_username_only(): void {
		$m = $this->mocks();
		$m['outbox']->method( 'listPending' )->willReturn( array(
			$this->row( 'deprovision', array( 'app' => 5, 'target' => 'i.petrov', 'idem' => 'deprovision:app:5' ) ),
		) );

		$job = $this->service( $m )->pendingJobs()[0];

		self::assertSame( 'deprovision', $job['event'] );
		self::assertSame( 'i.petrov', $job['username'] );
		self::assertArrayNotHasKey( 'password', $job );
	}

	// ── promote ──────────────────────────────────────────────────────────────

	public function test_enqueue_promote_resolves_username_from_person(): void {
		$enqueued = null;
		$m = $this->mocks();
		$m['persons']->method( 'find' )->willReturn( new PersonDTO(
			id: 42, wpUserId: 99, lastName: 'П', firstName: 'И', middleName: null,
			birthDate: null, isStudent: true, school: null, grade: null,
			expelledAt: null, createdAt: '2026-01-01 00:00:00', updatedAt: '2026-01-01 00:00:00'
		) );
		$wpUser = new \WP_User();
		$wpUser->user_login = 'i.petrov';
		$m['users']->method( 'find' )->willReturn( $wpUser );
		$m['outbox']->method( 'enqueue' )->willReturnCallback( function ( array $d ) use ( &$enqueued ) {
			$enqueued = $d;
			return 9;
		} );

		$this->service( $m )->enqueuePromoteByPerson( 42 );

		self::assertSame( 'promote', $enqueued['event'] );
		self::assertSame( 42, $enqueued['person_id'] );
		self::assertSame( 'i.petrov', $enqueued['target'] );
		self::assertSame( 'promote:person:42', $enqueued['idempotency_key'] );
	}

	public function test_enqueue_promote_skips_when_no_wp_user(): void {
		$m = $this->mocks();
		$m['persons']->method( 'find' )->willReturn( null );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );
		$this->service( $m )->enqueuePromoteByPerson( 42 );
	}

	// ── ack / status ─────────────────────────────────────────────────────────

	public function test_ack_ok_and_fail(): void {
		$m = $this->mocks();
		$m['outbox']->expects( self::once() )->method( 'markSent' )->with( 7 );
		$this->service( $m )->ack( 7, true );

		$m2 = $this->mocks();
		$m2['outbox']->expects( self::once() )->method( 'markFailed' )->with( 7, 'boom' );
		$this->service( $m2 )->ack( 7, false, 'boom' );
	}

	public function test_status_maps_states(): void {
		foreach ( array( 'sent' => 'done', 'dead' => 'failed', 'pending' => 'pending', 'failed' => 'pending' ) as $raw => $expected ) {
			$m = $this->mocks();
			$m['outbox']->method( 'latestByApplication' )->willReturn( $this->row( 'provision', array( 'status' => $raw ) ) );
			self::assertSame( $expected, $this->service( $m )->statusForApplication( 5 ), "raw={$raw}" );
		}

		$m = $this->mocks();
		$m['outbox']->method( 'latestByApplication' )->willReturn( null );
		self::assertSame( 'none', $this->service( $m )->statusForApplication( 5 ) );
	}
}
