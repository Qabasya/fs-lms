<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\DTO\Application\ApplicationDTO;
use Inc\DTO\Person\PersonDTO;
use Inc\Managers\Person\UserManager;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\DTO\AdOutboxItemDTO;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\DTO\Enrollment\StudentRecordDTO;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Services\Security\PasswordGeneratorService;
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

	public function test_name_update_and_delete_have_correct_payloads(): void {
		$m = $this->mocks();
		$m['outbox']->method( 'latestByApplication' )->willReturn( $this->row( 'provision', array( 'app' => 5, 'target' => 'i.petrov' ) ) );
		$m['apps']->method( 'find' )->willReturn( $this->app() );
		$m['crypto']->method( 'decrypt' )->willReturn( $this->blobJson() );
		$queued = array();
		$m['outbox']->method( 'enqueue' )->willReturnCallback( static function ( array $data ) use ( &$queued ): int {
			$queued[] = $data;
			return count( $queued );
		} );

		$service = $this->service( $m );
		$service->enqueueNameUpdate( 5 );
		$service->enqueueDeleteByApplication( 5 );

		self::assertSame( array( 'rename', 'delete' ), array_column( $queued, 'event' ) );
		self::assertSame( 'i.petrov', $queued[1]['target'] );
		self::assertArrayNotHasKey( 'first', $queued[0] );
		self::assertArrayNotHasKey( 'password', $queued[1] );
		$rename = $service->payloadFor( $this->row( 'rename', array( 'app' => 5, 'target' => 'i.petrov' ) ) );
		self::assertSame( 'Петров', $rename['last'] );
		$delete = $service->payloadFor( $this->row( 'delete', array( 'app' => 5, 'target' => 'i.petrov' ) ) );
		self::assertSame( 'i.petrov', $delete['username'] );
	}

	public function test_deleting_old_application_keeps_account_reactivated_elsewhere(): void {
		$m = $this->mocks();
		$m['outbox']->method( 'latestByApplication' )->willReturn( $this->row( 'deprovision', array( 'app' => 5, 'target' => 'i.petrov' ) ) );
		$m['outbox']->method( 'latestByTarget' )->willReturn( $this->row( 'provision', array( 'person' => 42, 'target' => 'i.petrov' ) ) );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueueDeleteByApplication( 5 );
	}

	public function test_deleting_application_keeps_account_of_active_student(): void {
		$m = $this->mocks();
		$m['records']->method( 'findActiveByStudent' )->with( 42 )->willReturn( array( $this->record() ) );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueueDeleteByApplication( 5, 42 );
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
		$config = $this->createMock( AdSyncConfig::class );
		// По умолчанию направление разрешено — negative-кейсы переопределяют явно.
		$config->method( 'shouldProvision' )->willReturn( true );

		return array(
			'outbox'  => $this->createMock( AdOutboxRepository::class ),
			'apps'    => $this->createMock( ApplicationRepository::class ),
			'crypto'  => $this->createMock( PiiCryptoService::class ),
			'persons' => $this->createMock( PersonRepository::class ),
			'users'   => $this->createMock( UserManager::class ),
			'config'  => $config,
			'records' => $this->createMock( StudentRecordRepository::class ),
			'groups'  => $this->createMock( GroupsRepository::class ),
			'pass'    => $this->createMock( PasswordGeneratorService::class ),
		);
	}

	private function service( array $m ): AdProvisioningService {
		return new AdProvisioningService(
			$m['outbox'], $m['apps'], $m['crypto'], $m['persons'], $m['users'], $m['config'],
			$m['records'], $m['groups'], $m['pass']
		);
	}

	private function person( ?string $expelledAt = null ): PersonDTO {
		return new PersonDTO(
			id: 42, wpUserId: 99, lastName: 'Петров', firstName: 'Иван', middleName: null,
			birthDate: null, isStudent: true, school: null, grade: null,
			expelledAt: $expelledAt, createdAt: '2026-01-01 00:00:00', updatedAt: '2026-01-01 00:00:00'
		);
	}

	private function wpUser(): \WP_User {
		$user             = new \WP_User();
		$user->user_login = 'i.petrov';
		return $user;
	}

	private function record( int $id = 3, bool $trial = false ): StudentRecordDTO {
		return StudentRecordDTO::fromArray( array(
			'id' => $id, 'student_person_id' => 42, 'parent_person_id' => 43, 'group_id' => 8,
			'status' => 'active', 'is_trial' => $trial ? 1 : 0, 'enrolled_at' => '2026-09-01 00:00:00',
			'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
		) );
	}

	/** Зачисление в группу направления `inf_oge`; ученик — i.petrov с сохранённым паролем. */
	private function enrolledMocks( array $active = array() ): array {
		$m = $this->mocks();
		$m['persons']->method( 'findIncludingDeleted' )->willReturn( $this->person() );
		$m['persons']->method( 'findByWpUserId' )->willReturn( $this->person() );
		$m['users']->method( 'find' )->willReturn( $this->wpUser() );
		$m['records']->method( 'find' )->willReturn( $this->record() );
		$m['records']->method( 'findActiveByStudent' )->willReturn( $active );
		$m['groups']->method( 'findById' )->willReturn( (object) array( 'subject_key' => 'inf_oge' ) );
		$m['pass']->method( 'getCredentials' )->willReturn( array( 'login' => 'i.petrov', 'password' => 'Новый123' ) );
		return $m;
	}

	/** Перехват постановки в очередь: [$m, &$enqueued]. */
	private function capture( array $m, ?array &$enqueued ): void {
		$m['outbox']->method( 'enqueue' )->willReturnCallback( function ( array $d ) use ( &$enqueued ) {
			$enqueued = $d;
			return 1;
		} );
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

	public function test_enqueue_provision_skips_when_subject_not_in_list(): void {
		$m = $this->mocks();
		$m['config'] = $this->createMock( AdSyncConfig::class );
		$m['config']->method( 'shouldProvision' )->with( 'inf' )->willReturn( false );
		$m['apps']->method( 'find' )->willReturn( $this->app() );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );
		$this->service( $m )->enqueueProvision( 5 );
	}

	public function test_provision_payload_has_minimal_fields(): void {
		$m = $this->mocks();
		$m['apps']->method( 'find' )->willReturn( $this->app() );
		$m['crypto']->method( 'decrypt' )->willReturn( $this->blobJson() );

		$job = $this->service( $m )->payloadFor( $this->row( 'provision', array( 'app' => 5, 'idem' => 'app:5' ) ) );

		self::assertSame( 'i.petrov', $job['username'] );
		self::assertSame( 'Secret123', $job['password'] );
		self::assertSame( 'Иван', $job['first'] );
		self::assertSame( 'Петров', $job['last'] );
		self::assertSame( 'inf', $job['subject_key'] );
		self::assertSame( 'app:5', $job['idempotency_key'] );
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
		// Ранее по заявке ставился provision — deprovision разрешён.
		$m['outbox']->method( 'latestByApplication' )->willReturn( $this->row( 'provision', array( 'app' => 5, 'idem' => 'app:5' ) ) );
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

	public function test_enqueue_deprovision_by_application_skips_without_prior_provision(): void {
		$m = $this->mocks();
		$m['outbox']->method( 'latestByApplication' )->willReturn( null );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );
		$this->service( $m )->enqueueDeprovisionByApplication( 5 );
	}

	public function test_provision_payload_is_null_when_application_is_gone(): void {
		$m = $this->mocks();
		$m['apps']->method( 'find' )->willReturn( null );

		self::assertNull( $this->service( $m )->payloadFor( $this->row( 'provision', array( 'app' => 5 ) ) ) );
	}

	public function test_deprovision_payload_has_username_only(): void {
		$m = $this->mocks();

		$job = $this->service( $m )->payloadFor(
			$this->row( 'deprovision', array( 'app' => 5, 'target' => 'i.petrov', 'idem' => 'deprovision:app:5' ) )
		);

		self::assertSame( 'deprovision', $job['event'] );
		self::assertSame( 'i.petrov', $job['username'] );
		self::assertArrayNotHasKey( 'password', $job );
	}

	// ── deprovision by person (отчисление зачисленного ученика) ────────────────

	public function test_enqueue_deprovision_by_person_resolves_username_from_person(): void {
		$enqueued = null;
		$m = $this->mocks();
		// Регресс: хук отчисления приходит ПОСЛЕ мягкого удаления лица — логин всё равно находится.
		$m['persons']->method( 'findIncludingDeleted' )->willReturn( $this->person( '2026-09-28 10:00:00' ) );
		$wpUser = new \WP_User();
		$wpUser->user_login = 'i.petrov';
		$m['users']->method( 'find' )->willReturn( $wpUser );
		$m['outbox']->method( 'enqueue' )->willReturnCallback( function ( array $d ) use ( &$enqueued ) {
			$enqueued = $d;
			return 9;
		} );

		$this->service( $m )->enqueueDeprovisionByPerson( 42 );

		self::assertSame( 'deprovision', $enqueued['event'] );
		self::assertSame( 42, $enqueued['person_id'] );
		self::assertSame( 'i.petrov', $enqueued['target'] );
		self::assertSame( 'deprovision:person:42', $enqueued['idempotency_key'] );
	}

	public function test_enqueue_deprovision_by_person_skips_when_no_wp_user(): void {
		$m = $this->mocks();
		$m['persons']->method( 'findIncludingDeleted' )->willReturn( null );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );
		$this->service( $m )->enqueueDeprovisionByPerson( 42 );
	}

	public function test_expulsion_keeps_account_while_other_enrollment_is_active(): void {
		$m = $this->enrolledMocks( array( $this->record( 4 ) ) );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueueDeprovisionByPerson( 42 );
	}

	public function test_expulsion_ignores_trial_access_as_remaining_enrollment(): void {
		$enqueued = null;
		$m = $this->enrolledMocks( array( $this->record( 4, true ) ) );
		$this->capture( $m, $enqueued );

		$this->service( $m )->enqueueDeprovisionByPerson( 42 );

		self::assertSame( 'deprovision', $enqueued['event'] );
	}

	// ── повторное зачисление ─────────────────────────────────────────────────

	public function test_reenrollment_reactivates_deprovisioned_account(): void {
		$enqueued = null;
		$m = $this->enrolledMocks( array( $this->record() ) );
		$m['outbox']->method( 'latestByTarget' )->willReturn( $this->row( 'deprovision', array( 'target' => 'i.petrov' ) ) );
		$this->capture( $m, $enqueued );

		$this->service( $m )->enqueueReactivation( 3, 42 );

		self::assertSame( 'provision', $enqueued['event'] );
		self::assertSame( 42, $enqueued['person_id'] );
		self::assertSame( 'i.petrov', $enqueued['target'] );
		self::assertSame( 'reenroll:record:3', $enqueued['idempotency_key'] );
		self::assertArrayNotHasKey( 'password', $enqueued );
	}

	public function test_first_enrollment_after_application_does_not_reactivate(): void {
		$m = $this->enrolledMocks( array( $this->record() ) );
		$m['outbox']->method( 'latestByTarget' )->willReturn( $this->row( 'provision', array( 'app' => 5, 'target' => 'i.petrov' ) ) );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueueReactivation( 3, 42 );
	}

	public function test_reenrollment_to_subject_without_domain_account_is_skipped(): void {
		$m = $this->enrolledMocks();
		$m['config'] = $this->createMock( AdSyncConfig::class );
		$m['config']->method( 'shouldProvision' )->willReturn( false );
		$m['outbox']->method( 'latestByTarget' )->willReturn( $this->row( 'deprovision', array( 'target' => 'i.petrov' ) ) );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueueReactivation( 3, 42 );
	}

	public function test_reactivation_payload_uses_current_password_and_new_subject(): void {
		$m = $this->enrolledMocks( array( $this->record() ) );

		$job = $this->service( $m )->payloadFor( $this->row( 'provision', array( 'person' => 42, 'target' => 'i.petrov', 'idem' => 'reenroll:record:3' ) ) );

		self::assertSame( 'provision', $job['event'] );
		self::assertSame( 'i.petrov', $job['username'] );
		self::assertSame( 'Новый123', $job['password'] );
		self::assertSame( array( 'Иван', 'Петров' ), array( $job['first'], $job['last'] ) );
		self::assertSame( 'inf_oge', $job['subject_key'] );
	}

	public function test_reactivation_payload_is_null_without_saved_password(): void {
		$m = $this->mocks();
		$m['persons']->method( 'findIncludingDeleted' )->willReturn( $this->person() );
		$m['pass']->method( 'getCredentials' )->willReturn( null );

		self::assertNull( $this->service( $m )->payloadFor( $this->row( 'provision', array( 'person' => 42 ) ) ) );
	}

	// ── смена пароля ─────────────────────────────────────────────────────────

	public function test_password_change_is_queued_without_password(): void {
		$enqueued = null;
		$m = $this->enrolledMocks( array( $this->record() ) );
		$this->capture( $m, $enqueued );

		$this->service( $m )->enqueuePasswordChange( 99 );

		self::assertSame( 'password', $enqueued['event'] );
		self::assertSame( 'i.petrov', $enqueued['target'] );
		self::assertStringStartsWith( 'password:person:42:', $enqueued['idempotency_key'] );
		self::assertStringNotContainsString( 'Новый123', (string) json_encode( $enqueued, JSON_UNESCAPED_UNICODE ) );
	}

	public function test_password_change_skipped_for_deprovisioned_account(): void {
		$m = $this->enrolledMocks( array( $this->record() ) );
		$m['outbox']->method( 'latestByTarget' )->willReturn( $this->row( 'deprovision', array( 'target' => 'i.petrov' ) ) );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueuePasswordChange( 99 );
	}

	public function test_password_change_skipped_for_student_without_domain_account(): void {
		$m = $this->enrolledMocks( array( $this->record() ) );
		$m['config'] = $this->createMock( AdSyncConfig::class );
		$m['config']->method( 'shouldProvision' )->willReturn( false );
		$m['outbox']->expects( self::never() )->method( 'enqueue' );

		$this->service( $m )->enqueuePasswordChange( 99 );
	}

	public function test_password_payload_reads_current_password(): void {
		$m = $this->enrolledMocks();

		$job = $this->service( $m )->payloadFor( $this->row( 'password', array( 'person' => 42, 'target' => 'i.petrov', 'idem' => 'password:person:42:1' ) ) );

		self::assertSame(
			array( 'id' => 7, 'event' => 'password', 'idempotency_key' => 'password:person:42:1', 'username' => 'i.petrov', 'password' => 'Новый123' ),
			$job
		);
	}

	// ── status ───────────────────────────────────────────────────────────────

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
