<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Enums;

/**
 * Статус строки реестра записей (fs_lms_video_recordings.status).
 *
 * @package Inc\Modules\VideoLibrary\Enums
 */
enum VideoRecordingStatus: string {

	/** Привязана к занятию (group_lesson_id задан). */
	case Matched = 'matched';

	/** Занятие не найдено/неоднозначно — ждёт ручной привязки. */
	case Unmatched = 'unmatched';

	/** Человекочитаемое название. */
	public function label(): string {
		return match ( $this ) {
			self::Matched   => 'Привязана',
			self::Unmatched => 'Без занятия',
		};
	}
}
