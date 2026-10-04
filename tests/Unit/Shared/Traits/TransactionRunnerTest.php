<?php

declare( strict_types=1 );

namespace Tests\Unit\Shared\Traits;

use Inc\Shared\Traits\TransactionRunner;
use PHPUnit\Framework\TestCase;

/**
 * Границы транзакции проверяются: провал START TRANSACTION или COMMIT не выглядит как успех.
 */
class TransactionRunnerTest extends TestCase {

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

	public function test_success_commits_and_returns_result(): void {
		$db = $this->useDatabase();

		$result = $this->runner()->inTransaction( static fn (): string => 'готово' );

		self::assertSame( 'готово', $result );
		self::assertSame( array( 'START TRANSACTION', 'COMMIT' ), $db->log );
	}

	public function test_failed_start_throws_and_never_runs_body(): void {
		$db     = $this->useDatabase( array( 'START TRANSACTION' ) );
		$called = false;

		try {
			$this->runner()->inTransaction( static function () use ( &$called ): void {
				$called = true;
			} );
			self::fail( 'Провал START TRANSACTION должен бросать исключение.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'START TRANSACTION', $e->getMessage() );
		}

		self::assertFalse( $called, 'Тело не должно выполняться вне транзакции.' );
		self::assertSame( array( 'START TRANSACTION' ), $db->log );
	}

	public function test_failed_commit_throws_and_rolls_back(): void {
		$db = $this->useDatabase( array( 'COMMIT' ) );

		try {
			$this->runner()->inTransaction( static fn (): string => 'x' );
			self::fail( 'Провал COMMIT должен бросать исключение.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'COMMIT', $e->getMessage() );
		}

		self::assertSame( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), $db->log );
	}

	public function test_body_exception_rolls_back_and_is_rethrown(): void {
		$db = $this->useDatabase();

		try {
			$this->runner()->inTransaction( static function (): void {
				throw new \DomainException( 'правило нарушено' );
			} );
			self::fail( 'Исключение тела должно пробрасываться.' );
		} catch ( \DomainException $e ) {
			self::assertSame( 'правило нарушено', $e->getMessage() );
		}

		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->log );
	}
}
