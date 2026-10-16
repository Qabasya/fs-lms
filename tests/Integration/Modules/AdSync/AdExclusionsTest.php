<?php

declare( strict_types=1 );

namespace Integration\Modules\AdSync;

use Inc\Managers\Person\UserManager;
use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Repositories\AdOutboxRepository;
use Inc\Modules\AdSync\Repositories\AdSyncStateRepository;
use Inc\Modules\AdSync\Services\AdAuditLogger;
use Inc\Modules\AdSync\Services\AdDeliveryService;
use Inc\Modules\AdSync\Services\AdExclusionService;
use Inc\Modules\AdSync\Services\AdProvisioningService;
use Inc\Modules\AdSync\Services\AdServerClient;
use Inc\Modules\AdSync\DTO\AdServerResponseDTO;
use Inc\Repositories\WPDBRepositories\ApplicationRepository;
use Inc\Repositories\WPDBRepositories\PersonRepository;
use Inc\Repositories\WPDBRepositories\StudentRecordRepository;
use Inc\Repositories\WPDBRepositories\GroupsRepository;
use Inc\Services\Security\PiiCryptoService;
use Inc\Services\Security\PasswordGeneratorService;
use PHPUnit\Framework\TestCase;

/** Реальные запросы репозитория к изолированной SQLite; AD и HTTP заменены дублями. */
class AdExclusionsTest extends TestCase {
	private \wpdb $db;
	private AdOutboxRepository $outbox;
	private AdSyncConfig $config;
	private AdSyncStateRepository $state;
	private array $before;

	protected function setUp(): void {
		$this->before = $GLOBALS['_test_options'] ?? array();
		unset( $GLOBALS['_test_options'][AdSyncConfig::OPTION] );
		$this->db = new class extends \wpdb {
			public \PDO $pdo;
			public function __construct() {
				$this->pdo = new \PDO( 'sqlite::memory:' );
				$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
				$this->pdo->exec( 'CREATE TABLE wp_fs_lms_ad_outbox (
					id INTEGER PRIMARY KEY AUTOINCREMENT, event TEXT, application_id INTEGER, person_id INTEGER,
					target TEXT, idempotency_key TEXT, status TEXT, attempts INTEGER DEFAULT 0,
					next_attempt_at TEXT, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, sent_at TEXT
				)' );
			}
			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[sd]/', function ( array $match ) use ( &$i, $args ): string {
					$value = $args[$i++];
					return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
				}, $sql );
			}
			public function query( string $sql ): bool|int { return $this->pdo->exec( $sql ); }
			public function insert( string $table, array $data, ?array $format = null ): int|false {
				$query = $this->pdo->prepare( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')' );
				$query->execute( array_values( $data ) );
				$this->insert_id = (int) $this->pdo->lastInsertId();
				return 1;
			}
			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|false {
				$query = $this->pdo->prepare( 'UPDATE ' . $table . ' SET ' . implode( ',', array_map( static fn( string $key ): string => $key . '=?', array_keys( $data ) ) ) . ' WHERE ' . implode( ' AND ', array_map( static fn( string $key ): string => $key . '=?', array_keys( $where ) ) ) );
				$query->execute( array_merge( array_values( $data ), array_values( $where ) ) );
				return $query->rowCount();
			}
			public function get_row( string $query, string $output = 'OBJECT', int $y = 0 ): mixed { return $this->pdo->query( $query )->fetchObject() ?: null; }
			public function get_results( string $query, string $output = 'OBJECT' ): array { return $this->pdo->query( $query )->fetchAll( \PDO::FETCH_OBJ ); }
		};
		$this->outbox = new AdOutboxRepository( $this->db );
		$this->config = new AdSyncConfig();
		$this->state = $this->createMock( AdSyncStateRepository::class );
		$this->state->method( 'acquireLock' )->willReturn( true );
	}

	protected function tearDown(): void { $GLOBALS['_test_options'] = $this->before; }

	private function exclusions(): AdExclusionService { return new AdExclusionService( $this->config, $this->outbox, $this->state ); }

	private function delivery( AdServerClient $client, ?ApplicationRepository $applications = null ): AdDeliveryService {
		$provisioning = new AdProvisioningService(
			$this->outbox, $applications ?? $this->createMock( ApplicationRepository::class ), $this->createMock( PiiCryptoService::class ),
			$this->createMock( PersonRepository::class ), $this->createMock( UserManager::class ), $this->config,
			$this->createMock( StudentRecordRepository::class ), $this->createMock( GroupsRepository::class ),
			$this->createMock( PasswordGeneratorService::class )
		);
		$client->method( 'notReadyReason' )->willReturn( '' );
		return new AdDeliveryService( $this->outbox, $provisioning, $client, $this->state, $this->createMock( AdAuditLogger::class ), $this->config );
	}

	public function test_excluding_user_cancels_password_retries_and_delivers_only_cleanup(): void {
		foreach ( array( 6, 18 ) as $id ) {
			$this->db->insert( 'wp_fs_lms_ad_outbox', array( 'id' => $id, 'event' => 'password', 'target' => 'Test', 'idempotency_key' => 'password:' . $id, 'status' => 'dead' ) );
		}
		$other = $this->outbox->enqueue( array( 'event' => 'deprovision', 'target' => 'other', 'idempotency_key' => 'other' ) );
		$sent = $this->outbox->enqueue( array( 'event' => 'provision', 'target' => 'test', 'idempotency_key' => 'sent-test' ) );
		$this->outbox->markSent( $sent );
		self::assertSame( array( 'cancelled' => 2, 'queued' => 1 ), $this->exclusions()->save( array( 'TEST' ) ) );
		self::assertSame( 'sent', $this->outbox->find( $sent )->status );
		self::assertSame( 'pending', $this->outbox->find( $other )->status );
		self::assertSame( 'cancelled', $this->outbox->find( 6 )->status );
		self::assertSame( 'cancelled', $this->outbox->find( 18 )->status );
		$this->outbox->retryDead();
		self::assertSame( array( 'cancelled' => 0, 'queued' => 0 ), $this->exclusions()->save( array( 'test' ) ) );
		$client = $this->createMock( AdServerClient::class );
		$sentPayloads = array();
		$client->expects( self::exactly( 2 ) )->method( 'request' )->willReturnCallback( static function ( string $method, string $path, array $payload ) use ( &$sentPayloads ): AdServerResponseDTO {
			$sentPayloads[] = array( $payload['event'], $payload['username'] );
			return new AdServerResponseDTO( 200, array( 'status' => 'done', 'outcome' => 'delete_absent' ) );
		} );
		self::assertSame( 2, $this->delivery( $client )->deliverPending()['sent'] );
		self::assertSame( array( array( 'deprovision', 'other' ), array( 'delete', 'test' ) ), $sentPayloads );
		self::assertSame( 0, $this->outbox->countByStatus()['dead'] );
	}

	public function test_retry_cancels_deleted_sources_preserves_cleanup_and_keeps_real_data_errors(): void {
		foreach ( array( 1, 2, 3 ) as $id ) {
			$jobId = $this->outbox->enqueue( array( 'event' => 'provision', 'application_id' => $id, 'idempotency_key' => 'app:' . $id ) );
			$this->outbox->markDead( $jobId, 'Старая общая ошибка' );
		}
		$cleanup = $this->outbox->enqueue( array( 'event' => 'delete', 'application_id' => 4, 'target' => 'old.user', 'idempotency_key' => 'delete:app:4' ) );
		$this->outbox->markDead( $cleanup, 'Временная ошибка' );
		$broken = $this->outbox->enqueue( array( 'event' => 'provision', 'application_id' => 5, 'target' => 'active.user', 'idempotency_key' => 'app:5' ) );
		$this->outbox->markDead( $broken, 'Старая общая ошибка' );
		$applications = $this->createMock( ApplicationRepository::class );
		$applications->method( 'find' )->willReturnCallback( static fn( int $id ): ?\Inc\DTO\Application\ApplicationDTO => 5 === $id ? \Inc\DTO\Application\ApplicationDTO::fromArray( array( 'id' => 5, 'status' => 'ready_for_review', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01' ) ) : null );
		$client = $this->createMock( AdServerClient::class );
		$client->expects( self::once() )->method( 'request' )->with( 'POST', '/v1/jobs', self::callback( static fn( array $payload ): bool => 'delete' === $payload['event'] && 'old.user' === $payload['username'] ) )->willReturn( new AdServerResponseDTO( 200, array( 'status' => 'done', 'outcome' => 'delete_absent' ) ) );
		$delivery = $this->delivery( $client, $applications );
		self::assertSame( array( 'retried' => 2, 'cancelled' => 3 ), $delivery->retryDead() );
		$report = $delivery->deliverPending();
		self::assertSame( 1, $report['sent'] );
		self::assertSame( 1, $report['dead'] );
		self::assertSame( 'sent', $this->outbox->find( $cleanup )->status );
		self::assertSame( 'dead', $this->outbox->find( $broken )->status );
		self::assertStringContainsString( 'В заявке №5 не сохранены данные', $this->outbox->find( $broken )->lastError );
		self::assertSame( array( 'retried' => 1, 'cancelled' => 0 ), $delivery->retryDead() );
		foreach ( array( 1, 2, 3 ) as $id ) {
			self::assertSame( 'cancelled', $this->outbox->find( $id )->status );
			self::assertStringContainsString( 'удалена', $this->outbox->find( $id )->lastError );
		}
	}

	public function test_removing_exclusion_cancels_queued_deletion_and_readding_queues_a_new_one(): void {
		$this->exclusions()->save( array( 'test' ) );
		self::assertCount( 1, $this->outbox->listPending() );
		$this->exclusions()->save( array() );
		self::assertSame( array(), $this->outbox->listPending() );
		self::assertFalse( $this->config->isExcluded( 'test' ) );
		self::assertSame( 1, $this->exclusions()->save( array( 'test' ) )['queued'] );
		self::assertCount( 1, $this->outbox->listPending() );
	}
}
