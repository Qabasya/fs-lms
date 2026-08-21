<?php

declare( strict_types=1 );

/**
 * Uninstall handler for Future Step LMS plugin
 *
 * Выполняется при удалении плагина со страницы плагинов WordPress. Это
 * единственный момент жизненного цикла, когда данные плагина уничтожаются:
 * активация таблицы создаёт, деактивация не трогает ни одной строки.
 *
 * @package    fs-lms
 * @since      1.0.0
 * @see        WP_UNINSTALL_PLUGIN
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || die( 'Direct access to this file is not allowed.' );

require_once __DIR__ . '/vendor/autoload.php';

use Inc\Managers\Person\RoleManager;

global $wpdb;

// 1. Таблицы плагина — по префиксу, а не перечислением.
// Так уносятся и таблицы модулей (`fs_lms_ad_outbox`, `fs_lms_video_recordings`),
// о которых ядро по архитектуре не знает и знать не должно, и любая таблица,
// добавленная позже: список в коде деинсталлятора неизбежно отстал бы.
$like   = $wpdb->esc_like( $wpdb->prefix . 'fs_lms_' ) . '%';
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
}

// 2. Опции плагина одним запросом.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM $wpdb->options WHERE option_name LIKE %s",
		$wpdb->esc_like( 'fs_lms_' ) . '%'
	)
);

// 3. Роли и права.
// unregisterAll() идемпотентен — деактивация обычно успевает снять роли раньше,
// но удаление каталога плагина мимо админки её не вызывает.
$roles = new RoleManager();
$roles->unregisterAll();
$roles->purgeAdminCaps();
