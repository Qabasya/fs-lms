<?php

declare( strict_types=1 );

namespace Tests\Integration\Http;

use Inc\Enums\Wp\PageRoutes;
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
}
