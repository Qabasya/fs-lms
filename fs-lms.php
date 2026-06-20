<?php

/**
 * Plugin Name:     FS LMS
 * Plugin URI:      https://github.com/Qabasya/fs-lms
 * Description:     Плагин для управления заданиями ЕГЭ (пока что).
 * Version:         0.0.1
 * Author:          FutureStep
 * Author URI:      https://future-step.ru/
 * Text Domain:     fs-lms
 * License:         GPL2
 * License URI:     https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 **/

use Inc\Contracts\ClockInterface;
use Inc\Contracts\LogEventDispatcherInterface;
use Inc\Controllers\System\AdminController;
use Inc\Controllers\Settings\ConfigController;
use Inc\Core\Activate;
use Inc\Core\Container;
use Inc\Core\Deactivate;
use Inc\Core\Enqueue;
use Inc\Services\Log\LogEventDispatcher;
use Inc\Services\Security\PiiCryptoService;
use Inc\Services\Shared\WpClock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FS_LMS_PATH', plugin_dir_path( __FILE__ ) );

require_once FS_LMS_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( Activate::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivate::class, 'deactivate' ) );

if ( ! PiiCryptoService::isAvailable() ) {
	add_action( 'admin_notices', array( Activate::class, 'showConfigNotice' ) );

	$_minimal_container = new Container();
	$_minimal_container->bind( ClockInterface::class, WpClock::class );
	$_minimal_container->bind( LogEventDispatcherInterface::class, LogEventDispatcher::class );

	foreach ( array( Enqueue::class, AdminController::class, ConfigController::class ) as $_class ) {
		$_minimal_container->get( $_class )->register();
	}

	return;
}

Inc\Init::run();
