<?php
/**
 * Recording URL resolution.
 *
 * @package FS_LMS
 */

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Services;

use Inc\Modules\VideoLibrary\Config\VideoLibraryConfig;

/** Stable per-user signed recording URLs and issuance telemetry. */
class RecordingUrlService {
	private const URL_CACHE_PREFIX = 'fsvid_url_';

	/** Receives the collaborators for this module operation. */
	public function __construct(
		private readonly S3UrlSigner $signer,
		private readonly VideoLibraryConfig $config,
		private readonly VideoAccessLogService $accessLog,
	) {}

	/** Resolve a pointer and reuse a still-valid signed URL. */
	public function resolve( ?string $url, ?int $groupLessonId = null ): ?string {
		if ( null === $url || ! preg_match( '#^s3://([^/]+)/(.+)$#', $url, $matches ) ) {
			return $url;
		}
		if ( ! $this->config->s3Ready() ) {
			return $url;
		}
		$this->accessLog->logPresign( $matches[1], $matches[2], $groupLessonId );
		$key    = self::URL_CACHE_PREFIX . md5( get_current_user_id() . '|' . $matches[1] . '|' . $matches[2] );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$ttl    = $this->config->presignTtl();
		$signed = $this->signer->presign( $matches[1], $matches[2], $ttl );
		// Cache must never outlive the signature, including short configured lifetimes.
		set_transient( $key, $signed, max( 1, $ttl - min( 300, intdiv( $ttl, 2 ) ) ) );
		return $signed;
	}
}
