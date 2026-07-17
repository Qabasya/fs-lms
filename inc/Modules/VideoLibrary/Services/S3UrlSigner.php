<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;

/**
 * Class S3UrlSigner
 *
 * AWS SigV4 **query presign** (GET) на чистом PHP — без SDK (CLAUDE.md: только встроенные API).
 * Path-style URL: `{endpoint}/{bucket}/{key}` (Beget S3 требует path-style addressing).
 *
 * Схема подписи (https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html):
 * canonical request (GET, канонический URI, отсортированный query, host-заголовок,
 * UNSIGNED-PAYLOAD) → string to sign → signing key (цепочка hmac_sha256:
 * date → region → service → aws4_request) → подпись в `X-Amz-Signature`.
 *
 * @package Inc\Modules\VideoLibrary\Services
 */
class S3UrlSigner {

	private const ALGORITHM = 'AWS4-HMAC-SHA256';
	private const SERVICE   = 's3';

	public function __construct(
		private readonly VideoLibraryConfig $config,
	) {}

	/**
	 * Временная presigned-ссылка GET на объект приватного бакета.
	 *
	 * @param string   $bucket Имя бакета.
	 * @param string   $key    Ключ объекта (без ведущего «/»).
	 * @param int|null $ttl    Время жизни в секундах (null — из конфига модуля).
	 * @param int|null $now    Момент подписи (unix; null — time()). Параметр для тестов.
	 */
	public function presign( string $bucket, string $key, ?int $ttl = null, ?int $now = null ): string {
		$s3      = $this->config->s3();
		$ttl     = $ttl ?? $this->config->presignTtl();
		$now     = $now ?? time();
		$amzDate = gmdate( 'Ymd\THis\Z', $now );
		$scope   = gmdate( 'Ymd', $now ) . '/' . $s3['region'] . '/' . self::SERVICE . '/aws4_request';

		$endpoint = rtrim( $s3['endpoint'], '/' );
		$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$uri      = '/' . $bucket . '/' . $this->encodeKey( ltrim( $key, '/' ) );

		$query = array(
			'X-Amz-Algorithm'     => self::ALGORITHM,
			'X-Amz-Credential'    => $s3['key'] . '/' . $scope,
			'X-Amz-Date'          => $amzDate,
			'X-Amz-Expires'       => (string) $ttl,
			'X-Amz-SignedHeaders' => 'host',
		);
		ksort( $query );

		$canonicalQuery = implode( '&', array_map(
			static fn( string $k, string $v ): string => rawurlencode( $k ) . '=' . rawurlencode( $v ),
			array_keys( $query ),
			$query
		) );

		$canonicalRequest = implode( "\n", array(
			'GET',
			$uri,
			$canonicalQuery,
			'host:' . $host,
			'',
			'host',
			'UNSIGNED-PAYLOAD',
		) );

		$stringToSign = implode( "\n", array(
			self::ALGORITHM,
			$amzDate,
			$scope,
			hash( 'sha256', $canonicalRequest ),
		) );

		$signature = hash_hmac( 'sha256', $stringToSign, $this->signingKey( $s3['secret'], gmdate( 'Ymd', $now ), $s3['region'] ) );

		return $endpoint . $uri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
	}

	/** Цепочка AWS4: date → region → service → aws4_request. */
	private function signingKey( string $secret, string $dateStamp, string $region ): string {
		$kDate    = hash_hmac( 'sha256', $dateStamp, 'AWS4' . $secret, true );
		$kRegion  = hash_hmac( 'sha256', $region, $kDate, true );
		$kService = hash_hmac( 'sha256', self::SERVICE, $kRegion, true );

		return hash_hmac( 'sha256', 'aws4_request', $kService, true );
	}

	/** RFC 3986-кодирование ключа с сохранением разделителей сегментов «/». */
	private function encodeKey( string $key ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}
}
