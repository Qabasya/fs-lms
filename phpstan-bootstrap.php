<?php

declare( strict_types=1 );

/**
 * Bootstrap для PHPStan: константы главного файла плагина (fs-lms.php не входит
 * в анализируемые пути, анализатор их не видит). Значения здесь не используются,
 * важно само определение.
 */

if ( ! defined( 'FS_LMS_PATH' ) ) {
	define( 'FS_LMS_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'FS_LMS_VERSION' ) ) {
	define( 'FS_LMS_VERSION', '0.0.0-phpstan' );
}
if ( ! defined( 'FS_LMS_PLUGIN_FILE' ) ) {
	define( 'FS_LMS_PLUGIN_FILE', dirname( __DIR__ ) . '/fs-lms.php' );
}
