<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Schema;

/**
 * Class AdSchema
 *
 * Модуль AdSync владеет СВОЕЙ таблицей `fs_lms_ad_outbox` — ядро (core Migration/TableName)
 * её не знает. Создание идемпотентно и version-gated собственной опцией `fs_lms_ad_schema_version`
 * (один дешёвый option-read; dbDelta вызывается только при смене версии).
 *
 * @package Inc\Modules\AdSync\Schema
 */
class AdSchema {

	private const VERSION_OPTION = 'fs_lms_ad_schema_version';
	private const VERSION        = '2';

	/** Имя таблицы с префиксом WP. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fs_lms_ad_outbox';
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
				id              bigint unsigned     NOT NULL AUTO_INCREMENT,
				event           varchar(20)         NOT NULL,
				application_id  int unsigned        DEFAULT NULL,
				person_id       int unsigned        DEFAULT NULL,
				target          varchar(100)        DEFAULT NULL,
				idempotency_key varchar(100)        NOT NULL,
				status          varchar(20)         NOT NULL DEFAULT 'pending',
				attempts        smallint unsigned   NOT NULL DEFAULT 0,
				next_attempt_at datetime            DEFAULT NULL,
				last_error      text                DEFAULT NULL,
				created_at      datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
				sent_at         datetime            DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY idempotency_key (idempotency_key)
			) $collate;"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
