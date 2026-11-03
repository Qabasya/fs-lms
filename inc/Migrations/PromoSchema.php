<?php
/**
 * Общая схема промо.
 *
 * @package Inc\Migrations
 */

declare( strict_types=1 );

namespace Inc\Migrations;

use Inc\Contracts\ServiceInterface;
use Inc\Enums\Settings\OptionName;
use Inc\Enums\Settings\TableName;
use Inc\Shared\PluginLogger;

/** Общая схема промо независимо от включения отдельных модулей. */
class PromoSchema implements ServiceInterface {

	private const VERSION = '3';

	/**
	 * Обновляет схему на ранее установленных сайтах.
	 */
	public function register(): void {
		if ( get_option( OptionName::PromoSchemaVersion->value ) === self::VERSION ) {
			return;
		}
		try {
			$this->ensure();
		} catch ( \Throwable $e ) {
			PluginLogger::exception( 'PromoSchema', $e, array(), true );
		}
	}

	/**
	 * Применяет схему; отметка версии сохраняется только после проверки таблиц.
	 *
	 * @throws \RuntimeException При ошибке создания таблиц.
	 */
	private function ensure(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = array( TableName::PromoAccounts, TableName::PromoEvents, TableName::PromoEventLinks );
		foreach ( self::ddl( $wpdb->get_charset_collate() ) as $index => $sql ) {
			$name = $tables[ $index ]->prefixed();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- проверка перед созданием таблицы.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			if ( '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'Не удалось проверить таблицу промо.' );
			}
			// Существующие таблицы не изменяем автоматически: dbDelta может усечь данные при смене типа.
			if ( $found !== $name ) {
				dbDelta( $sql );
				if ( '' !== (string) $wpdb->last_error ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- исключение не является HTML.
					throw new \RuntimeException( 'Не удалось создать таблицу промо: ' . $wpdb->last_error );
				}
			}
		}
		$id        = array( 'bigint unsigned', 'NO', null, 'auto_increment' );
		$bigint    = array( 'bigint unsigned', 'NO', null, '' );
		$optional  = array( 'bigint unsigned', 'YES', null, '' );
		$date      = array( 'datetime', 'NO', null, '' );
		$maybeDate = array( 'datetime', 'YES', null, '' );
		$common    = array(
			'id'         => $id,
			'created_at' => $date,
			'updated_at' => $date,
		);
		$this->verifyColumns(
			TableName::PromoAccounts,
			$common + array(
				'user_id'           => $bigint,
				'person_id'         => array( 'int unsigned', 'NO', null, '' ),
				'origin_type'       => array( 'varchar(32)', 'NO', null, '' ),
				'origin_id'         => $optional,
				'expires_at'        => $date,
				'source_expires_at' => $maybeDate,
				'revoked_at'        => $maybeDate,
			)
		);
		$this->verifyColumns(
			TableName::PromoEvents,
			$common + array(
				'source_type' => array( 'varchar(32)', 'NO', null, '' ),
				'source_id'   => $bigint,
				'course_id'   => $optional,
			)
		);
		$this->verifyColumns(
			TableName::PromoEventLinks,
			$common + array(
				'account_id'        => $bigint,
				'event_id'          => $bigint,
				'participant_id'    => $bigint,
				'expires_at'        => $date,
				'source_expires_at' => $maybeDate,
				'revoked_at'        => $maybeDate,
				'course_override'   => array( 'tinyint', 'NO', '0', '' ),
				'course_id'         => $optional,
			)
		);
		$this->verifyIndexes(
			TableName::PromoAccounts,
			array(
				'PRIMARY'    => array( true, array( 'id' ) ),
				'user_id'    => array( true, array( 'user_id' ) ),
				'person_id'  => array( false, array( 'person_id' ) ),
				'expires_at' => array( false, array( 'expires_at' ) ),
			)
		);
		$this->verifyIndexes(
			TableName::PromoEvents,
			array(
				'PRIMARY' => array( true, array( 'id' ) ),
				'source'  => array( true, array( 'source_type', 'source_id' ) ),
			)
		);
		$this->verifyIndexes(
			TableName::PromoEventLinks,
			array(
				'PRIMARY'           => array( true, array( 'id' ) ),
				'event_participant' => array( true, array( 'event_id', 'participant_id' ) ),
				'event_account'     => array( true, array( 'event_id', 'account_id' ) ),
				'account_id'        => array( false, array( 'account_id' ) ),
			)
		);
		update_option( OptionName::PromoSchemaVersion->value, self::VERSION, false );
	}

	/**
	 * Проверяет свойства колонок, включая обязательность и генерацию идентификатора.
	 *
	 * @param array<string, array{string, string, ?string, string}> $required Описание колонок.
	 * @throws \RuntimeException Если схема не соответствует контракту.
	 */
	private function verifyColumns( TableName $table, array $required ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- проверка схемы после DDL.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table->prefixed() ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Не удалось проверить колонки промо.' );
		}
		$actual = array_column( $rows, null, 'Field' );
		foreach ( $required as $name => $expected ) {
			if ( ! isset( $actual[ $name ] ) ) {
				throw new \RuntimeException( 'Схема промо создана не полностью.' );
			}
			$row = $actual[ $name ];
			// MySQL 8 и MariaDB отображают ширину целых чисел по-разному.
			$type    = preg_replace( '/^(bigint|int|tinyint)\(\d+\)/', '$1', strtolower( (string) $row['Type'] ) );
			$default = null === $row['Default'] ? null : (string) $row['Default'];
			if ( array( $type, $row['Null'], $default, strtolower( (string) $row['Extra'] ) ) !== $expected ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- исключение не является HTML.
				throw new \RuntimeException( 'Некорректная колонка схемы промо: ' . $name );
			}
		}
	}

	/**
	 * Проверяет движок и индексы, включая первичный ключ и длину индексируемых значений.
	 *
	 * @param array<string, array{bool, list<string>}> $required Индексы.
	 * @throws \RuntimeException Если индекс или движок не соответствует схеме.
	 */
	private function verifyIndexes( TableName $table, array $required ): void {
		global $wpdb;
		$name = $table->prefixed();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- проверка схемы после DDL.
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $name ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $status ) || 'innodb' !== strtolower( (string) $status['Engine'] ) ) {
			throw new \RuntimeException( 'Таблица промо должна использовать InnoDB.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- проверка схемы после DDL.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $name ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new \RuntimeException( 'Не удалось проверить индексы промо.' );
		}
		foreach ( $required as $index => $expected ) {
			$columns = array();
			foreach ( $rows as $row ) {
				if ( $index !== $row['Key_name'] ) {
					continue;
				}
				if ( ( 0 === (int) $row['Non_unique'] ) !== $expected[0] || null !== $row['Sub_part'] || 'BTREE' !== strtoupper( (string) $row['Index_type'] ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- исключение не является HTML.
					throw new \RuntimeException( 'Некорректный индекс схемы промо: ' . $index );
				}
				$columns[ (int) $row['Seq_in_index'] ] = (string) $row['Column_name'];
			}
			ksort( $columns );
			if ( array_keys( $columns ) !== range( 1, count( $expected[1] ) ) || array_values( $columns ) !== $expected[1] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- исключение не является HTML.
				throw new \RuntimeException( 'Индекс промо создан не полностью: ' . $index );
			}
		}
	}

	/** DDL используется при чистой установке и на ранее установленных сайтах.
	 *
	 * @return list<string>
	 */
	public static function ddl( string $collate ): array {
		$accounts = TableName::PromoAccounts->prefixed();
		$events   = TableName::PromoEvents->prefixed();
		$links    = TableName::PromoEventLinks->prefixed();

		return array(
			"CREATE TABLE $accounts (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				person_id int unsigned NOT NULL,
				origin_type varchar(32) NOT NULL,
				origin_id bigint(20) unsigned DEFAULT NULL,
				expires_at datetime NOT NULL,
				source_expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				KEY person_id (person_id),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_type varchar(32) NOT NULL,
				source_id bigint(20) unsigned NOT NULL,
				course_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source (source_type,source_id)
			) ENGINE=InnoDB $collate;",
			"CREATE TABLE $links (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				account_id bigint(20) unsigned NOT NULL,
				event_id bigint(20) unsigned NOT NULL,
				participant_id bigint(20) unsigned NOT NULL,
				expires_at datetime NOT NULL,
				source_expires_at datetime DEFAULT NULL,
				revoked_at datetime DEFAULT NULL,
				course_override tinyint(1) NOT NULL DEFAULT 0,
				course_id bigint(20) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_participant (event_id,participant_id),
				UNIQUE KEY event_account (event_id,account_id),
				KEY account_id (account_id)
			) ENGINE=InnoDB $collate;",
		);
	}
}
