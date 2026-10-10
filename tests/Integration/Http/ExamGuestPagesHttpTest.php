<?php

declare( strict_types=1 );

namespace Tests\Integration\Http;

use Inc\Enums\Wp\PageRoutes;
use Inc\Services\Exam\GuestSessionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Проверяет ответы работающего WordPress; FS_LMS_HTTP_BASE_URL — адрес dev-стенда без авторизации. */
class ExamGuestPagesHttpTest extends TestCase {

	public static function routes(): array {
		return array_map( static fn ( PageRoutes $route ): array => array( $route ), PageRoutes::guestExamPages() );
	}

	#[DataProvider( 'routes' )]
	public function test_guest_without_credentials_gets_protected_404( PageRoutes $route ): void {
		$base = getenv( 'FS_LMS_HTTP_BASE_URL' );
		if ( ! is_string( $base ) || '' === $base ) {
			self::markTestSkipped( 'Запустить с FS_LMS_HTTP_BASE_URL работающего dev-стенда.' );
		}

		$context = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20 ) ) );
		$body = file_get_contents( rtrim( $base, '/' ) . '/' . $route->value . '/', false, $context );
		self::assertIsString( $body );
		$headers = $http_response_header ?? array();
		self::assertMatchesRegularExpression( '~^HTTP/\S+ 404\b~', $headers[0] ?? '' );
		$joined = strtolower( implode( "\n", $headers ) );
		self::assertStringContainsString( 'x-robots-tag: noindex, nofollow', $joined );
		self::assertStringContainsString( 'no-store', $joined );
		self::assertStringContainsString( 'referrer-policy: no-referrer', $joined );
		self::assertDoesNotMatchRegularExpression( '~<link\b[^>]*rel=[\'\"]canonical[\'\"]~i', $body );
	}

	/** Requires an admitted synthetic guest key issued on the running dev stand. */
	public function test_valid_entry_exchanges_only_guest_cookie_and_keeps_wordpress_anonymous(): void {
		$base = getenv( 'FS_LMS_HTTP_BASE_URL' );
		$key = getenv( 'FS_LMS_HTTP_ENTRY_KEY' );
		if ( ! is_string( $base ) || '' === $base || ! is_string( $key ) || '' === $key ) {
			self::markTestSkipped( 'Requires FS_LMS_HTTP_BASE_URL and FS_LMS_HTTP_ENTRY_KEY from an isolated fixture.' );
		}

		$path = '/' . PageRoutes::ExamEntry->value . '/';
		$exchange = $this->request( $base, $path . '?k=' . rawurlencode( $key ) );
		self::assertMatchesRegularExpression( '~^HTTP/\S+ 302\b~', $exchange['headers'][0] ?? '' );
		$cookie = '';
		$location = '';
		foreach ( $exchange['headers'] as $header ) {
			if ( preg_match( '~^Set-Cookie: (' . preg_quote( GuestSessionService::COOKIE_ENTRY, '~' ) . '=[a-f0-9]{64})(?:;|$)~i', $header, $matches ) ) {
				$cookie = $matches[1];
				self::assertStringContainsString( 'httponly', strtolower( $header ) );
				self::assertStringContainsString( 'samesite=lax', strtolower( $header ) );
			}
			if ( 0 === stripos( $header, 'Location: ' ) ) {
				$location = substr( $header, 10 );
			}
		}
		self::assertNotSame( '', $cookie );
		self::assertSame( $path, parse_url( $location, PHP_URL_PATH ) );
		self::assertNull( parse_url( $location, PHP_URL_QUERY ) );
		$this->assertNoWordPressAuthCookie( $exchange['headers'] );

		$page = $this->request( $base, $path, $cookie );
		self::assertMatchesRegularExpression( '~^HTTP/\S+ 200\b~', $page['headers'][0] ?? '' );
		self::assertStringContainsString( 'id="fs-exam-entry"', $page['body'] );
		$this->assertNoWordPressAuthCookie( $page['headers'] );

		$me = $this->request( $base, '/wp-json/wp/v2/users/me', $cookie );
		self::assertMatchesRegularExpression( '~^HTTP/\S+ 401\b~', $me['headers'][0] ?? '' );
	}

	/** @return array{headers: list<string>, body: string} */
	private function request( string $base, string $path, string $cookie = '' ): array {
		$headers = array();
		if ( '' !== $cookie ) {
			$headers[] = 'Cookie: ' . $cookie;
		}
		$host = getenv( 'FS_LMS_HTTP_HOST' );
		if ( is_string( $host ) && '' !== $host ) {
			self::assertMatchesRegularExpression( '~^[a-z0-9.-]+(?::[0-9]+)?$~i', $host );
			$headers[] = 'Host: ' . $host;
		}
		$context = stream_context_create( array( 'http' => array(
			'ignore_errors' => true,
			'follow_location' => 0,
			'timeout' => 20,
			'header' => implode( "\r\n", $headers ),
		) ) );
		$body = file_get_contents( rtrim( $base, '/' ) . $path, false, $context );
		self::assertIsString( $body );
		return array( 'headers' => $http_response_header ?? array(), 'body' => $body );
	}

	/** @param list<string> $headers */
	private function assertNoWordPressAuthCookie( array $headers ): void {
		self::assertDoesNotMatchRegularExpression( '~^Set-Cookie:\s*wordpress_(?:logged_in_|sec_|[a-f0-9]{32}=)~im', implode( "\n", $headers ) );
	}

}
