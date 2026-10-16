<?php

declare( strict_types=1 );

namespace Tests\Unit\Repositories\Log;

use Inc\Repositories\WPDBRepositories\Log\AuthLogRepository;
use PHPUnit\Framework\TestCase;

/**
 * Поиск по логину в журнале аутентификации: подстрока без регистра, спецсимволы LIKE экранируются.
 */
final class AuthLogRepositoryTest extends TestCase {

	/** @var array<int, mixed> Параметры последнего prepare() */
	private array $bindings = array();

	private string $query = '';

	private function repository(): AuthLogRepository {
		$wpdb = $this->getMockBuilder( \wpdb::class )->disableOriginalConstructor()->onlyMethods( array( 'prepare', 'get_var', 'esc_like' ) )->getMock();
		$wpdb->method( 'esc_like' )->willReturnCallback( static fn ( string $text ): string => addcslashes( $text, '_%\\' ) );
		$wpdb->method( 'prepare' )->willReturnCallback(
			function ( string $query, $args ): string {
				$this->query    = $query;
				$this->bindings = (array) $args;

				return 'SQL';
			}
		);
		$wpdb->method( 'get_var' )->willReturn( '0' );

		return new AuthLogRepository( $wpdb );
	}

	public function test_login_filter_builds_like_with_escaped_wildcards(): void {
		$this->repository()->countFiltered( array( 'login' => '50%_user' ) );

		self::assertStringContainsString( 'login_identifier LIKE %s', $this->query );
		self::assertContains( '%50\%\_user%', $this->bindings );
	}

	public function test_empty_login_adds_no_condition(): void {
		$this->repository()->countFiltered( array( 'login' => '' ) );

		self::assertStringNotContainsString( 'LIKE', $this->query );
	}
}
