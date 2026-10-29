<?php

declare( strict_types=1 );

namespace Inc\Modules\Masterclass;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\Masterclass\Config\MasterclassConfig;
use Inc\Modules\Masterclass\Controllers\MasterclassAdminController;
use Inc\Modules\Masterclass\Controllers\MasterclassAuthController;
use Inc\Modules\Masterclass\Controllers\MasterclassIntegrityController;
use Inc\Modules\Masterclass\Controllers\MasterclassPlayerController;
use Inc\Modules\Masterclass\Controllers\MasterclassSettingsController;
use Inc\Modules\Masterclass\Schema\MasterclassSchema;

/**
 * Class MasterclassModule
 *
 * Bootstrap изолируемого модуля Masterclass (публичные мастер-классы во внешних школах: временные аккаунты
 * `student-N`, флаеры, вход в плеер без регистрации, воронка до заявки). Единственная точка входа модуля;
 * регистрируется одной строкой в `Init::getServices()`. Ядро о внутренностях модуля не знает — связь только через
 * универсальные швы (`.docs/masterclass-feature/CONTRACTS.md` §2) и общие хуки WordPress.
 *
 * Порядок `register()` важен:
 *  1) схема — ВСЕГДА и первой: шов доступа, `deleted_user` и Ш4 читают таблицы даже при выключенном модуле;
 *  2) настройки — всегда, иначе модуль нечем включить;
 *  3) целостность (Ш4, дальше Ш1/Ш3/`deleted_user`) — всегда;
 *  4) остальное (страница, вход, плеер, зачисление, крон) — только при включённом модуле.
 *
 * Уровни выключения (ModularArchitecture.md §3.2):
 *  1) тумблер в опции `fs_lms_masterclass.enabled` (Dashboard);
 *  2) константа `FS_LMS_MASTERCLASS` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/Masterclass/` + этой строки в `Init`.
 *
 * @package Inc\Modules\Masterclass
 */
class MasterclassModule implements ServiceInterface {

	public function __construct(
		private readonly MasterclassSchema $schema,
		private readonly MasterclassConfig $config,
		private readonly MasterclassSettingsController $settings,
		private readonly MasterclassIntegrityController $integrity,
		private readonly MasterclassAdminController $admin,
		private readonly MasterclassAuthController $auth,
		private readonly MasterclassPlayerController $player,
	) {}

	public function register(): void {
		$this->schema->ensure();
		$this->settings->register();
		$this->integrity->register();

		if ( ! $this->config->isEnabled() ) {
			return;
		}

		$this->admin->register();
		$this->auth->register();
		$this->player->register();
	}
}
