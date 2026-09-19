<?php

declare( strict_types=1 );

namespace Inc\Modules\PublicExams\Config;

use Inc\Modules\EgeComputer\Config\EgeComputerConfig;

/**
 * Class PublicExamsConfig
 *
 * Флаг включения модуля публичных экзаменов. Уровни выключения:
 *  1) константа FS_LMS_PUBLIC_EXAMS = false в wp-config.php;
 *  2) выключенный модуль EgeComputer (публичный экзамен — это станция ЕГЭ:
 *     без неё нечего показывать, поэтому модуль молча ничего не регистрирует);
 *  3) удаление каталога `inc/Modules/PublicExams/` + строки в `Init::getServices()`.
 *
 * @package Inc\Modules\PublicExams\Config
 */
class PublicExamsConfig {

	public function __construct(
		private readonly EgeComputerConfig $station,
	) {}

	public function isEnabled(): bool {
		if ( defined( 'FS_LMS_PUBLIC_EXAMS' ) && ! (bool) constant( 'FS_LMS_PUBLIC_EXAMS' ) ) {
			return false;
		}

		return $this->station->isEnabled();
	}
}
