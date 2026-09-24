<?php

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Enums\Course\LessonVisibility;
use Inc\Enums\Settings\TableName;

/**
 * Class DraftLessonOpenMigration
 *
 * Урок курса в статусе черновика больше не открывается ученикам сам по дате
 * занятия ({@see \Inc\Services\Course\LessonVisibilityService::effectiveVisibility()}).
 * Авто-открытие ленивое — в БД такие строки так и лежат `hidden`, поэтому без
 * миграции прошедшие занятия с уроком-черновиком, которые ученики уже видели и
 * сдавали, пропали бы из их кабинета. Здесь уже случившееся открытие
 * фиксируется в БД: `visibility = open`, `opened_at` = дата занятия — ровно то,
 * что ленивое открытие отдавало на чтение.
 *
 * Data-миграция, version-gated собственной опцией (паттерн
 * {@see LearningEventActorMigration}).
 *
 * @package Inc\Migrations
 */
class DraftLessonOpenMigration {

	private const VERSION_OPTION = 'fs_lms_draft_lesson_open_version';
	private const VERSION        = '1';

	public function run(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		$this->materialize();

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	private function materialize(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i gl
				 INNER JOIN %i p ON p.ID = gl.lesson_id
				 SET gl.visibility = %s, gl.opened_at = gl.scheduled_at
				 WHERE gl.visibility = %s
				   AND gl.scheduled_at IS NOT NULL
				   AND gl.scheduled_at <= %s
				   AND p.post_status <> 'publish'",
				TableName::GroupLessons->prefixed(),
				$wpdb->posts,
				LessonVisibility::Open->value,
				LessonVisibility::Hidden->value,
				current_time( 'mysql' )
			)
		);
		// phpcs:enable
	}
}
