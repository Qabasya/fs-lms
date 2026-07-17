<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\DTO;

/**
 * Class VideoRecordingDTO
 *
 * Строка реестра видеозаписей занятий (`fs_lms_video_recordings`).
 *
 * @package Inc\Modules\VideoLibrary\DTO
 */
readonly class VideoRecordingDTO {

	public function __construct(
		public int     $id,
		public string  $s3Bucket,
		public string  $s3Key,
		public ?string $manifestKey,
		public string  $groupSlug,
		public ?int    $groupId,
		public ?int    $teacherUserId,
		public ?int    $groupLessonId,
		public string  $status,
		public string  $recordedAt,
		public int     $sizeBytes,
		public string  $sha256,
		public ?int    $durationSec,
		public ?string $payload,
		public string  $createdAt,
		public string  $updatedAt,
	) {}

	public static function fromRow( object $row ): self {
		return new self(
			id:            (int) $row->id,
			s3Bucket:      (string) $row->s3_bucket,
			s3Key:         (string) $row->s3_key,
			manifestKey:   $row->manifest_key ?? null,
			groupSlug:     (string) ( $row->group_slug ?? '' ),
			groupId:       isset( $row->group_id ) ? (int) $row->group_id : null,
			teacherUserId: isset( $row->teacher_user_id ) ? (int) $row->teacher_user_id : null,
			groupLessonId: isset( $row->group_lesson_id ) ? (int) $row->group_lesson_id : null,
			status:        (string) $row->status,
			recordedAt:    (string) $row->recorded_at,
			sizeBytes:     (int) $row->size_bytes,
			sha256:        (string) ( $row->sha256 ?? '' ),
			durationSec:   isset( $row->duration_sec ) ? (int) $row->duration_sec : null,
			payload:       $row->payload ?? null,
			createdAt:     (string) $row->created_at,
			updatedAt:     (string) $row->updated_at,
		);
	}
}
