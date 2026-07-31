<?php

declare( strict_types=1 );

namespace Inc\Modules\AdSync\Config;

use Inc\Modules\Shared\ModuleConfig;

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
class AdSyncConfig extends ModuleConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_ad_sync';

	private const DEFAULTS = array(
		'enabled'            => false,
		// Ключи предметов, по которым создаются доменные учётки. Пустой список = никого.
		'provision_subjects' => array(),
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
		return 'FS_LMS_AD_SYNC';
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

	/** @return string[] Ключи предметов, по которым создаются доменные учётки. */
	public function provisionSubjects(): array {
		$list = $this->get()['provision_subjects'] ?? array();
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	/**
	 * Нужно ли ставить provision-задание для заявки с данным направлением.
	 * Пустой список предметов = не провижнить никого (админ выбирает направления явно).
	 */
	public function shouldProvision( ?string $subjectKey ): bool {
		return null !== $subjectKey
			&& '' !== $subjectKey
			&& in_array( $subjectKey, $this->provisionSubjects(), true );
	}
}
