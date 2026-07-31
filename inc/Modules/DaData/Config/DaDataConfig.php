<?php

declare( strict_types=1 );

namespace Inc\Modules\DaData\Config;

use Inc\Modules\Shared\ModuleConfig;

/**
 * Class DaDataConfig
 *
 * Конфигурация модуля DaData (автодополнение ФИО/адреса на форме /lms/join).
 * Модуль владеет СВОЕЙ опцией `fs_lms_dadata` — ядро о ней не знает.
 *
 * Уровни выключения:
 *  1) тумблер на странице «Статистика» (опция `fs_lms_dadata.enabled`);
 *  2) константа `FS_LMS_DADATA` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/DaData/` + строки в `Init::getServices()`.
 *
 * Токен может задаваться константой `DADATA_API_TOKEN` в wp-config.php (перекрывает опцию).
 *
 * @package Inc\Modules\DaData\Config
 */
class DaDataConfig extends ModuleConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_dadata';

	/** Константа wp-config с токеном (перекрывает опцию). */
	private const TOKEN_CONSTANT = 'DADATA_API_TOKEN';

	private const DEFAULTS = array(
		'enabled' => false,
		'token'   => '',
	);

	protected function option(): string {
		return self::OPTION;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function defaults(): array {
		return self::DEFAULTS;
	}

	protected function toggleConstant(): ?string {
		return 'FS_LMS_DADATA';
	}

	/** API-токен DaData. Константа wp-config перекрывает значение из опции. */
	public function token(): string {
		if ( $this->tokenFromConstant() ) {
			return (string) constant( self::TOKEN_CONSTANT );
		}
		return (string) ( $this->get()['token'] ?? '' );
	}

	/** Задан ли токен через wp-config (тогда поле в UI только для чтения). */
	public function tokenFromConstant(): bool {
		return defined( self::TOKEN_CONSTANT ) && '' !== (string) constant( self::TOKEN_CONSTANT );
	}

	/**
	 * Разовая миграция из легаси core-конфига: если своя опция ещё не создана, а в
	 * `fs_lms_plugin_config` лежит непустой `dadata_token` — переносим его сюда и включаем
	 * модуль, чтобы существующие установки продолжили работать без ручных действий.
	 *
	 * @return void
	 */
	public function maybeMigrateFromCore(): void {
		if ( $this->optionExists() ) {
			return; // своя опция уже есть — миграция не нужна
		}

		$legacy = get_option( 'fs_lms_plugin_config', array() );
		$token  = is_array( $legacy ) ? trim( (string) ( $legacy['dadata_token'] ?? '' ) ) : '';

		if ( '' !== $token ) {
			update_option( self::OPTION, array( 'enabled' => true, 'token' => $token ), false );
		}
	}
}
