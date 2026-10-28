<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass\Schema;

/**
 * Class MasterclassSchema
 *
 * Модуль Masterclass владеет ТРЕМЯ своими таблицами — ядро (core Migration/TableName) их не знает:
 * `fs_lms_masterclasses` (мастер-класс = окно + срок жизни), `fs_lms_masterclass_accounts` (временные
 * аккаунты; `id` и есть номер N, строки не удаляются) и `fs_lms_masterclass_log` (журнал модуля).
 *
 * Создание идемпотентно и version-gated собственной опцией `fs_lms_masterclass_schema_version`
 * (один дешёвый option-read; dbDelta только при смене версии). Паттерн — VideoSchema.
 * Вызывается ВСЕГДА, и при выключенном модуле: шов доступа и `deleted_user` читают эти таблицы.
 * Новая колонка в будущем — `SHOW COLUMNS … LIKE` + обычный `ADD COLUMN` и подъём `VERSION`
 * (в SQL нет `IF NOT EXISTS` у колонок — он есть только в MariaDB). Таблицы сносит общий `uninstall.php`
 * по префиксу `fs_lms_`.
 *
 * Время во всех колонках — UTC (переводит только `ExamTime`). `group_id` — тип `groups.id`
 * (`smallint unsigned`): больший id БД молча обрежет.
 *
 * @package Inc\Modules\Masterclass\Schema
 */
class MasterclassSchema {

	public const VERSION_OPTION = 'fs_lms_masterclass_schema_version';
	public const VERSION        = '1';

	/** Имя таблицы мастер-классов с префиксом WP. */
	public static function masterclasses(): string {
		global $wpdb;
		return $wpdb->prefix . 'fs_lms_masterclasses';
	}

	/** Имя таблицы временных аккаунтов с префиксом WP. */
	public static function accounts(): string {
		global $wpdb;
		return $wpdb->prefix . 'fs_lms_masterclass_accounts';
	}

	/** Имя таблицы журнала с префиксом WP. */
	public static function log(): string {
		global $wpdb;
		return $wpdb->prefix . 'fs_lms_masterclass_log';
	}

	/** Создаёт/обновляет таблицы при смене версии. */
	public function ensure(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->masterclassesDdl( $collate ) );
		dbDelta( $this->accountsDdl( $collate ) );
		dbDelta( $this->logDdl( $collate ) );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/** DDL мастер-классов. `group_id` UNIQUE: одна группа — один мастер-класс; NULL после удаления группы. */
	public function masterclassesDdl( string $collate ): string {
		$table = self::masterclasses();

		return "CREATE TABLE $table (
			id                  int unsigned        NOT NULL AUTO_INCREMENT,
			school_name         varchar(255)        NOT NULL,
			subject_key         varchar(50)         NOT NULL,
			lesson_id           bigint(20) unsigned NOT NULL,
			group_id            smallint unsigned   DEFAULT NULL,
			group_lesson_id     int unsigned        DEFAULT NULL,
			teacher_user_id     bigint(20) unsigned NOT NULL,
			starts_at           datetime            NOT NULL,
			ends_at             datetime            NOT NULL,
			freeze_at           datetime            NOT NULL,
			unused_purge_at     datetime            NOT NULL,
			purge_at            datetime            NOT NULL,
			frozen_at           datetime            DEFAULT NULL,
			unused_purged_at    datetime            DEFAULT NULL,
			delete_requested_at datetime            DEFAULT NULL,
			purged_at           datetime            DEFAULT NULL,
			created_by_user_id  bigint(20) unsigned DEFAULT NULL,
			created_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY group_id (group_id),
			KEY freeze_at (freeze_at),
			KEY unused_purge_at (unused_purge_at),
			KEY purge_at (purge_at),
			KEY purged_at (purged_at)
		) $collate;";
	}

	/**
	 * DDL аккаунтов. `id` — номер N: строка не удаляется (мягкое удаление), поэтому номер не повторяется.
	 * `user_id` при удалении сохраняется (для журнала); UNIQUE не мешает — WordPress id не переиспользует.
	 */
	public function accountsDdl( string $collate ): string {
		$table = self::accounts();

		return "CREATE TABLE $table (
			id                int unsigned        NOT NULL AUTO_INCREMENT,
			masterclass_id    int unsigned        NOT NULL,
			user_id           bigint(20) unsigned DEFAULT NULL,
			person_id         int unsigned        DEFAULT NULL,
			student_record_id int unsigned        DEFAULT NULL,
			state             varchar(10)         NOT NULL DEFAULT 'active',
			first_login_at    datetime            DEFAULT NULL,
			home_at           datetime            DEFAULT NULL,
			contact_at        datetime            DEFAULT NULL,
			application_id    int unsigned        DEFAULT NULL,
			deleted_at        datetime            DEFAULT NULL,
			delete_reason     varchar(20)         DEFAULT NULL,
			created_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY masterclass_id (masterclass_id),
			KEY person_id (person_id),
			KEY deleted_at (deleted_at)
		) $collate;";
	}

	/** DDL журнала. Образец — `exam_log` (`Migration_1_0_89::examLogDdl`). */
	public function logDdl( string $collate ): string {
		$table = self::log();

		return "CREATE TABLE $table (
			id             int unsigned        NOT NULL AUTO_INCREMENT,
			action         varchar(40)         NOT NULL,
			result         varchar(10)         NOT NULL DEFAULT 'success',
			actor_type     varchar(10)         NOT NULL DEFAULT 'system',
			actor_user_id  bigint(20) unsigned DEFAULT NULL,
			actor_label    varchar(255)        DEFAULT NULL,
			masterclass_id int unsigned        DEFAULT NULL,
			account_id     int unsigned        DEFAULT NULL,
			subject_label  varchar(255)        DEFAULT NULL,
			actor_ip       varchar(45)         NOT NULL DEFAULT '',
			context        longtext            DEFAULT NULL,
			created_at     datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY masterclass_id (masterclass_id),
			KEY account_id (account_id),
			KEY created_at (created_at)
		) $collate;";
	}
}
