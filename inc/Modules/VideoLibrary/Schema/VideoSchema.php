<?php

declare( strict_types=1 );

namespace Inc\Modules\VideoLibrary\Schema;

/**
 * Class VideoSchema
 *
 * Модуль VideoLibrary владеет СВОЕЙ таблицей `fs_lms_video_recordings` — ядро (core Migration/TableName)
 * её не знает. Создание идемпотентно и version-gated собственной опцией `fs_lms_video_schema_version`
 * (один дешёвый option-read; dbDelta вызывается только при смене версии). Паттерн — AdSchema.
 *
 * `s3_key` — уникальный ключ идемпотентности (upsert повторных регистраций).
 * `group_lesson_id` NULL = unmatched (ручная привязка в админке).
 *
 * @package Inc\Modules\VideoLibrary\Schema
 */
class VideoSchema {

	private const VERSION_OPTION = 'fs_lms_video_schema_version';
	private const VERSION        = '1';

	/** Имя таблицы с префиксом WP. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fs_lms_video_recordings';
	}

	/** Создаёт/обновляет таблицу при смене версии. Вызывать только при включённом модуле. */
	public function ensure(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE $table (
				id              bigint unsigned   NOT NULL AUTO_INCREMENT,
				s3_bucket       varchar(100)      NOT NULL,
				s3_key          varchar(500)      NOT NULL,
				manifest_key    varchar(510)      DEFAULT NULL,
				group_slug      varchar(100)      NOT NULL DEFAULT '',
				group_id        smallint unsigned DEFAULT NULL,
				teacher_user_id bigint unsigned   DEFAULT NULL,
				group_lesson_id int unsigned      DEFAULT NULL,
				status          varchar(20)       NOT NULL DEFAULT 'unmatched',
				recorded_at     datetime          NOT NULL,
				size_bytes      bigint unsigned   NOT NULL DEFAULT 0,
				sha256          char(64)          NOT NULL DEFAULT '',
				duration_sec    int unsigned      DEFAULT NULL,
				payload         longtext          DEFAULT NULL,
				created_at      datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at      datetime          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY s3_key (s3_key),
				KEY group_lesson_id (group_lesson_id),
				KEY status (status),
				KEY group_recorded (group_id, recorded_at)
			) $collate;"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
