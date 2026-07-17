<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Config;

/**
 * Class AdSyncConfig
 *
 * Конфигурация модуля AdSync (синхронизация заявок с Active Directory).
 * Модуль владеет СВОЕЙ опцией `fs_lms_ad_sync` — ядро о ней не знает.
 *
 * Флаг включения: константа `FS_LMS_AD_SYNC` в wp-config.php перекрывает тумблер из опции
 * (3 уровня выключения — см. .docs/AdSyncPythonService.md).
 * Секрет HMAC живёт в `FS_LMS_AD_HMAC_SECRET` (wp-config), не в опции.
 *
 * @package Inc\Modules\AdSync\Config
 */
class AdSyncConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_ad_sync';

	private const DEFAULTS = array(
		'enabled' => false,
	);

	/** @return array<string, mixed> */
	public function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/** Мержит $partial поверх текущего значения; неизвестные ключи игнорирует. */
	public function save( array $partial ): void {
		$current = $this->get();
		$updated = array_merge( $current, array_intersect_key( $partial, self::DEFAULTS ) );
		update_option( self::OPTION, $updated, false );
	}

	/**
	 * Включён ли модуль в рантайме. Константа wp-config перекрывает тумблер.
	 */
	public function isEnabled(): bool {
		if ( defined( 'FS_LMS_AD_SYNC' ) ) {
			return (bool) constant( 'FS_LMS_AD_SYNC' );
		}
		return (bool) ( $this->get()['enabled'] ?? false );
	}

	/** Секрет HMAC из wp-config (для подписи запросов к Python). */
	public function hmacSecret(): string {
		return defined( 'FS_LMS_AD_HMAC_SECRET' ) ? (string) constant( 'FS_LMS_AD_HMAC_SECRET' ) : '';
	}
}
