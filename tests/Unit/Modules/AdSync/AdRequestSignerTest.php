<?php

declare( strict_types=1 );

namespace Unit\Modules\AdSync;

use Inc\Modules\AdSync\Config\AdSyncConfig;
use Inc\Modules\AdSync\Services\AdRequestSigner;
use PHPUnit\Framework\TestCase;

/**
 * Подпись исходящих запросов в офис: формула одна для сайта и Python-сервиса.
 */
class AdRequestSignerTest extends TestCase {

	private function signer( string $secret = 'topsecret' ): AdRequestSigner {
		$config = $this->createMock( AdSyncConfig::class );
		$config->method( 'hmacSecret' )->willReturn( $secret );

		return new AdRequestSigner( $config );
	}

	public function test_canonical_string_is_method_path_timestamp_body(): void {
		self::assertSame( "POST\n/v1/jobs\n1700000000\n{\"a\":1}", AdRequestSigner::canonical( 'post', '/v1/jobs', '1700000000', '{"a":1}' ) );
	}

	public function test_signature_matches_reference_hmac(): void {
		$headers = $this->signer()->headers( 'POST', '/v1/jobs', '{"a":1}', 1700000000 );

		self::assertSame( '1700000000', $headers['X-Fs-Timestamp'] );
		// Эталон для Python: hmac.new(b"topsecret", b'POST\n/v1/jobs\n1700000000\n{"a":1}', sha256).hexdigest()
		self::assertSame( hash_hmac( 'sha256', "POST\n/v1/jobs\n1700000000\n{\"a\":1}", 'topsecret' ), $headers['X-Fs-Signature'] );
	}

	public function test_path_and_method_are_part_of_signature(): void {
		$signer = $this->signer();
		$jobs   = $signer->headers( 'POST', '/v1/jobs', '{}', 1 )['X-Fs-Signature'];

		self::assertNotSame( $jobs, $signer->headers( 'POST', '/v1/reconcile', '{}', 1 )['X-Fs-Signature'] );
		self::assertNotSame( $jobs, $signer->headers( 'GET', '/v1/jobs', '{}', 1 )['X-Fs-Signature'] );
	}

	public function test_has_secret(): void {
		self::assertTrue( $this->signer()->hasSecret() );
		self::assertFalse( $this->signer( '' )->hasSecret() );
	}
}
