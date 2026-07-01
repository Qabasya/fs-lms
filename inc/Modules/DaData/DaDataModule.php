<?php

declare( strict_types=1 );

namespace Inc\Modules\DaData;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\DaData\Config\DaDataConfig;
use Inc\Modules\DaData\Controllers\DaDataSettingsController;

/**
 * Class DaDataModule
 *
 * Опциональный модуль — автодополнение ФИО/адреса через DaData на форме /lms/join.
 * Ядро о модуле не знает: токен попадает на фронт через фильтр `fs_lms_join_vars`.
 *
 * Уровни выключения:
 *  1) тумблер на странице «Статистика» (опция `fs_lms_dadata.enabled`);
 *  2) константа `FS_LMS_DADATA` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/DaData/` + строки в `Init::getServices()`.
 *
 * @package Inc\Modules\DaData
 */
class DaDataModule implements ServiceInterface {

	public function __construct(
		private readonly DaDataSettingsController $settings,
		private readonly DaDataConfig             $config,
	) {}

	public function register(): void {
		// Разовый перенос токена из легаси core-конфига (back-compat существующих установок).
		$this->config->maybeMigrateFromCore();

		// Admin-настройки (секция + dashboard-тоггл + сохранение) — всегда: чтобы можно было включить.
		$this->settings->register();

		if ( ! $this->config->isEnabled() ) {
			return;
		}

		// Рантайм: дописываем токен в переменные формы /lms/join (ядро строит их и прогоняет через фильтр).
		add_filter( 'fs_lms_join_vars', array( $this, 'addToken' ) );
	}

	/**
	 * @param array<string, mixed> $vars
	 * @return array<string, mixed>
	 */
	public function addToken( array $vars ): array {
		$vars['dadata_token'] = $this->config->token();
		return $vars;
	}
}
