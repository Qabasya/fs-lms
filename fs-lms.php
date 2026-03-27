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

use Inc\Core\Activate;
use Inc\Core\Deactivate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

register_activation_hook( __FILE__, [ Activate::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivate::class, 'deactivate' ] );

Inc\Init::run();