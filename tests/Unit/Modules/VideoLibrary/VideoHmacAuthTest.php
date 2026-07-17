<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Services\VideoHmacAuth;
use PHPUnit\Framework\TestCase;

class VideoHmacAuthTest extends TestCase {

	private const SECRET = 'test-video-secret';

	private VideoHmacAuth $auth;

	protected function setUp(): void {
		parent::setUp();
		$config = $this->createMock( VideoLibraryConfig::class );
		$config->method( 'hmacSecret' )->willReturn( self::SECRET );
		$this->auth = new VideoHmacAuth( $config );
	}

	private function signedRequest( string $body, ?int $ts = null, ?string $secret = null ): \WP_REST_Request {
		$ts      = $ts ?? time();
		$request = new \WP_REST_Request();
		$request->set_body( $body );
		$request->set_header( 'X-Fs-Timestamp', (string) $ts );
		$request->set_header( 'X-Fs-Signature', hash_hmac( 'sha256', $ts . '.' . $body, $secret ?? self::SECRET ) );
		return $request;
	}

	public function test_valid_signature_passes(): void {
		self::assertTrue( $this->auth->verify( $this->signedRequest( '{"s3_key":"a"}' ) ) );
	}

	public function test_wrong_secret_fails(): void {
		self::assertFalse( $this->auth->verify( $this->signedRequest( '{}', null, 'other-secret' ) ) );
	}

	public function test_tampered_body_fails(): void {
		$request = $this->signedRequest( '{"a":1}' );
		$request->set_body( '{"a":2}' );

		self::assertFalse( $this->auth->verify( $request ) );
	}

	public function test_stale_timestamp_fails(): void {
		self::assertFalse( $this->auth->verify( $this->signedRequest( '{}', time() - 301 ) ) );
	}

	public function test_missing_headers_fail(): void {
		$request = new \WP_REST_Request();
		$request->set_body( '{}' );

		self::assertFalse( $this->auth->verify( $request ) );
	}

	public function test_empty_secret_rejects_everything(): void {
		$config = $this->createMock( VideoLibraryConfig::class );
		$config->method( 'hmacSecret' )->willReturn( '' );
		$auth = new VideoHmacAuth( $config );

		self::assertFalse( $auth->verify( $this->signedRequest( '{}' ) ) );
	}
}
