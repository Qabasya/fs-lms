<?php

declare( strict_types=1 );

namespace Unit\Modules\VideoLibrary;

use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;
use Inc\Modules\VideoLibrary\Services\S3UrlSigner;
use PHPUnit\Framework\TestCase;

class S3UrlSignerTest extends TestCase {

	/**
	 * Known-good вектор: реквизиты из официального примера AWS SigV4
	 * (docs.aws.amazon.com → «Authenticating Requests: Using Query Parameters»),
	 * адаптированного к path-style (`/examplebucket/test.txt`, host `s3.amazonaws.com`).
	 * Подпись пересчитана независимой реализацией (Python hmac/hashlib); сама
	 * python-реализация сверена бит-в-бит с официальным virtual-hosted примером AWS
	 * (aeeed9bb…). Дата подписи: 2013-05-24T00:00:00Z, expires 86400.
	 */
	private const EXPECTED_SIGNATURE = '733255ef022bec3f2a8701cd61d4b371f3f28c9f193a1f02279211d48d5193d7';

	private function signer( array $s3Over = array() ): S3UrlSigner {
		$config = $this->createMock( VideoLibraryConfig::class );
		$config->method( 's3' )->willReturn( array_merge( array(
			'endpoint' => 'https://s3.amazonaws.com',
			'region'   => 'us-east-1',
			'bucket'   => 'examplebucket',
			'key'      => 'AKIAIOSFODNN7EXAMPLE',
			'secret'   => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
		), $s3Over ) );
		$config->method( 'presignTtl' )->willReturn( 86400 );
		return new S3UrlSigner( $config );
	}

	private function fixedNow(): int {
		return (int) ( new \DateTimeImmutable( '2013-05-24T00:00:00Z' ) )->getTimestamp();
	}

	public function test_presign_matches_known_good_vector(): void {
		$url = $this->signer()->presign( 'examplebucket', 'test.txt', 86400, $this->fixedNow() );

		self::assertStringEndsWith( '&X-Amz-Signature=' . self::EXPECTED_SIGNATURE, $url );
	}

	public function test_presign_builds_path_style_url_with_query_params(): void {
		$url = $this->signer()->presign( 'examplebucket', 'test.txt', 86400, $this->fixedNow() );

		self::assertStringStartsWith( 'https://s3.amazonaws.com/examplebucket/test.txt?', $url );
		self::assertStringContainsString( 'X-Amz-Algorithm=AWS4-HMAC-SHA256', $url );
		self::assertStringContainsString(
			'X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request',
			$url
		);
		self::assertStringContainsString( 'X-Amz-Date=20130524T000000Z', $url );
		self::assertStringContainsString( 'X-Amz-Expires=86400', $url );
		self::assertStringContainsString( 'X-Amz-SignedHeaders=host', $url );
	}

	public function test_key_segments_are_encoded_but_slashes_preserved(): void {
		$url = $this->signer()->presign( 'bucket', 'videos/kege-1/2026/07/файл с пробелом.webm', 3600, $this->fixedNow() );

		self::assertStringContainsString(
			'/bucket/videos/kege-1/2026/07/%D1%84%D0%B0%D0%B9%D0%BB%20%D1%81%20%D0%BF%D1%80%D0%BE%D0%B1%D0%B5%D0%BB%D0%BE%D0%BC.webm?',
			$url
		);
	}

	public function test_ttl_falls_back_to_config(): void {
		$url = $this->signer()->presign( 'examplebucket', 'test.txt', null, $this->fixedNow() );

		self::assertStringContainsString( 'X-Amz-Expires=86400', $url );
		self::assertStringEndsWith( '&X-Amz-Signature=' . self::EXPECTED_SIGNATURE, $url );
	}
}
