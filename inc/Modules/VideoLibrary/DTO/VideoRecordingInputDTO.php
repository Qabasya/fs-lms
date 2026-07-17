<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\DTO;

/**
 * Class VideoRecordingInputDTO
 *
 * Валидированный payload регистрации записи (`POST /videos`, FS_LMS_API.md §7.1).
 * Состав `lms`-блока определяет ветку резолва: `groupId` — групповая;
 * только `teacherUsername` — индивидуальная. `courseId`/`teacherId` — кросс-чек, не резолв.
 *
 * @package Inc\Modules\VideoLibrary\DTO
 */
readonly class VideoRecordingInputDTO {

	public function __construct(
		public string  $s3Bucket,
		public string  $s3Key,
		public ?string $manifestKey,
		public string  $groupSlug,
		public ?int    $groupId,
		public ?int    $courseId,
		public ?int    $teacherId,
		public ?string $teacherUsername,
		/** ISO-8601 с offset — время начала записи (ключ резолва). */
		public string  $recordedAt,
		public int     $sizeBytes,
		public string  $sha256,
		public ?int    $durationSec,
		/** Сырой JSON запроса (аудит/переразбор). */
		public string  $payload,
	) {}
}
