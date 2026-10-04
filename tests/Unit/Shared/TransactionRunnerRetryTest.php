<?php

declare( strict_types=1 );

namespace Tests\Unit\Shared;

use Inc\Repositories\WPDBRepositories\RetryableDbException;
use Inc\Shared\Traits\TransactionRunner;
use PHPUnit\Framework\TestCase;

/**
 * `inTransactionWithRetry()`: повтор только при взаимной блокировке/таймауте, любая бизнес-ошибка — откат и проброс;
 * `inTransaction()` повторов не знает (границы самой транзакции — в `Traits/TransactionRunnerTest`).
 */
class TransactionRunnerRetryTest extends TestCase {

	private \wpdb $originalWpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->originalWpdb = $GLOBALS['wpdb'];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->originalWpdb;
		parent::tearDown();
	}

	/** @param string[] $failing Операторы, на которых база «отказывает». */
	private function useDatabase( array $failing = array() ): object {
		$db = new class( $failing ) extends \wpdb {
			/** @var string[] */
			public array $log = array();

			/** @param string[] $failing */
			public function __construct( private array $failing ) {
			}

			public function query( string $sql ): bool|int {
				$this->log[] = $sql;
				if ( in_array( $sql, $this->failing, true ) ) {
					$this->last_error = 'нет соединения';
					return false;
				}
				return 1;
			}
		};

		$GLOBALS['wpdb'] = $db;
		return $db;
	}

	private function runner(): object {
		return new class() {
			use TransactionRunner;
		};
	}

	public function test_retries_on_retryable_exception_up_to_limit(): void {
		$db       = $this->useDatabase();
		$attempts = 0;

		try {
			$this->runner()->inTransactionWithRetry(
				static function () use ( &$attempts ): void {
					++$attempts;
					throw new RetryableDbException( 'deadlock' );
				},
				3
			);
			self::fail( 'После исчерпания попыток исключение пробрасывается.' );
		} catch ( RetryableDbException ) {
			self::assertSame( 3, $attempts );
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'ROLLBACK' ), $db->log );
		}
	}

	public function test_retry_succeeds_when_the_conflict_goes_away(): void {
		$db       = $this->useDatabase();
		$attempts = 0;

		$result = $this->runner()->inTransactionWithRetry( static function () use ( &$attempts ): string {
			++$attempts;
			if ( $attempts < 2 ) {
				throw new RetryableDbException( 'deadlock' );
			}
			return 'ok';
		} );

		self::assertSame( 'ok', $result );
		self::assertSame( 2, $attempts );
		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ), $db->log );
	}

	public function test_does_not_retry_business_exception(): void {
		$db       = $this->useDatabase();
		$attempts = 0;

		try {
			$this->runner()->inTransactionWithRetry( static function () use ( &$attempts ): void {
				++$attempts;
				throw new \LogicException( 'ошибка логики' );
			} );
			self::fail( 'Бизнес-исключение пробрасывается без повтора.' );
		} catch ( \LogicException ) {
			self::assertSame( 1, $attempts );
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log );
		}
	}

	public function test_retry_variant_checks_commit_too(): void {
		$db = $this->useDatabase( array( 'COMMIT' ) );

		try {
			$this->runner()->inTransactionWithRetry( static fn (): string => 'x' );
			self::fail( 'Провал COMMIT не должен выглядеть как успех.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'COMMIT', $e->getMessage() );
			self::assertSame( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), $db->log );
		}
	}

	public function test_in_transaction_behaviour_unchanged(): void {
		$db       = $this->useDatabase();
		$attempts = 0;

		try {
			$this->runner()->inTransaction( static function () use ( &$attempts ): void {
				++$attempts;
				throw new RetryableDbException( 'deadlock' );
			} );
			self::fail( 'Простая транзакция повторов не делает: исключение пробрасывается.' );
		} catch ( RetryableDbException ) {
			self::assertSame( 1, $attempts );
			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log );
		}

		$db->log = array();
		self::assertSame( 'готово', $this->runner()->inTransaction( static fn (): string => 'готово' ) );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $db->log );
	}
}