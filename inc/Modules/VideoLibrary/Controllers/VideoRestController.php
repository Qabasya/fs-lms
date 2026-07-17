<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Controllers;

use Inc\Modules\VideoLibrary\DTO\VideoRecordingInputDTO;
use Inc\Modules\VideoLibrary\Services\VideoHmacAuth;
use Inc\Modules\VideoLibrary\Services\VideoRegistrationService;

/**
 * Class VideoRestController
 *
 * REST-эндпоинт push-регистрации записей от сервиса fs-video-uploader:
 *   POST /wp-json/fs-lms/v1/videos — зарегистрировать загруженную в S3 запись.
 * Аутентификация — HMAC (VideoHmacAuth). Регистрируется только при включённом модуле.
 *
 * Контракт ответов — FS_LMS_API.md §7.3: «занятие не найдено» — это `200 matched:false`
 * (любой 4xx сервис трактует как терминальный fail); `400` — только структурная
 * невалидность payload.
 *
 * @package Inc\Modules\VideoLibrary\Controllers
 */
class VideoRestController {

	private const NAMESPACE = 'fs-lms/v1';

	public function __construct(
		private readonly VideoRegistrationService $service,
		private readonly VideoHmacAuth            $auth,
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		register_rest_route( self::NAMESPACE, '/videos', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'postVideo' ),
			'permission_callback' => fn( \WP_REST_Request $request ): bool => $this->auth->verify( $request ),
		) );
	}

	/** POST /videos → 200 { ok, matched, group_lesson_id } | 400 { ok:false, error }. */
	public function postVideo( \WP_REST_Request $request ): \WP_REST_Response {
		$error = '';
		$input = $this->parseInput( $request, $error );
		if ( null === $input ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $error ), 400 );
		}

		try {
			$result = $this->service->register( $input );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $e->getMessage() ), 400 );
		}

		return new \WP_REST_Response( array(
			'ok'              => true,
			'matched'         => $result['matched'],
			'group_lesson_id' => $result['group_lesson_id'],
		), 200 );
	}

	/** Структурная валидация тела (FS_LMS_API.md §7.1); null + $error при невалидности. */
	private function parseInput( \WP_REST_Request $request, string &$error ): ?VideoRecordingInputDTO {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array() === $body ) {
			$error = 'empty or non-json body';
			return null;
		}

		$s3Bucket   = trim( (string) ( $body['s3_bucket'] ?? '' ) );
		$s3Key      = trim( (string) ( $body['s3_key'] ?? '' ) );
		$recordedAt = trim( (string) ( $body['recorded_at'] ?? '' ) );

		if ( '' === $s3Bucket || '' === $s3Key ) {
			$error = 'missing s3_bucket or s3_key';
			return null;
		}
		if ( '' === $recordedAt || ! $this->isParseableDate( $recordedAt ) ) {
			$error = 'missing or invalid recorded_at (expected ISO-8601)';
			return null;
		}

		$lms = $body['lms'] ?? null;
		if ( ! is_array( $lms ) || array() === $lms ) {
			$error = 'missing lms block';
			return null;
		}
		foreach ( $lms as $value ) {
			if ( ! is_int( $value ) && ! is_string( $value ) && null !== $value ) {
				$error = 'lms block must be a flat object of scalars';
				return null;
			}
		}

		$groupId         = isset( $lms['group_id'] ) ? (int) $lms['group_id'] : null;
		$teacherUsername = isset( $lms['teacher_username'] ) ? trim( (string) $lms['teacher_username'] ) : '';

		if ( ( null === $groupId || $groupId <= 0 ) && '' === $teacherUsername ) {
			$error = 'lms block must contain group_id (int > 0) or teacher_username';
			return null;
		}

		return new VideoRecordingInputDTO(
			s3Bucket:        $s3Bucket,
			s3Key:           $s3Key,
			manifestKey:     isset( $body['manifest_key'] ) && '' !== (string) $body['manifest_key']
				? (string) $body['manifest_key']
				: null,
			groupSlug:       (string) ( $body['group_slug'] ?? '' ),
			groupId:         null !== $groupId && $groupId > 0 ? $groupId : null,
			courseId:        isset( $lms['course_id'] ) ? (int) $lms['course_id'] : null,
			teacherId:       isset( $lms['teacher_id'] ) ? (int) $lms['teacher_id'] : null,
			teacherUsername: '' !== $teacherUsername ? $teacherUsername : null,
			recordedAt:      $recordedAt,
			sizeBytes:       max( 0, (int) ( $body['size_bytes'] ?? 0 ) ),
			sha256:          (string) ( $body['sha256'] ?? '' ),
			durationSec:     isset( $body['duration_sec'] ) && null !== $body['duration_sec']
				? (int) $body['duration_sec']
				: null,
			payload:         (string) $request->get_body(),
		);
	}

	private function isParseableDate( string $value ): bool {
		try {
			new \DateTimeImmutable( $value );
			return true;
		} catch ( \Exception ) {
			return false;
		}
	}
}
