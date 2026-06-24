<?php

declare( strict_types=1 );

namespace Inc\Modules\EgeComputer\Config;

/**
 * Class EgeComputerConfig
 *
 * Флаги включения модуля ЕГЭ (Компьютер).
 * Уровни выключения:
 *  1) константа FS_LMS_EGE_COMPUTER в wp-config.php;
 *  2) удаление каталога `inc/Modules/EgeComputer/` + строки в Init.
 *
 * @package Inc\Modules\EgeComputer\Config
 */
class EgeComputerConfig {

	public function isEnabled(): bool {
		return ! defined( 'FS_LMS_EGE_COMPUTER' ) || (bool) constant( 'FS_LMS_EGE_COMPUTER' );
	}
}
