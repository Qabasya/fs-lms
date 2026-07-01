<?php

declare( strict_types=1 );

namespace Inc\Modules\SmartCaptcha\Config;

/**
 * Class SmartCaptchaConfig
 *
 * Конфигурация модуля SmartCaptcha (капча Yandex на форме /lms/apply).
 * Модуль владеет СВОЕЙ опцией `fs_lms_smart_captcha` — ядро о ней не знает.
 *
 * Уровни выключения:
 *  1) тумблер на странице «Статистика» (опция `fs_lms_smart_captcha.enabled`);
 *  2) константа `FS_LMS_SMART_CAPTCHA` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/SmartCaptcha/` + строки в `Init::getServices()`.
 *
 * Ключи можно задавать константами `FS_LMS_CAPTCHA_SITE_KEY` / `FS_LMS_CAPTCHA_SERVER_KEY`
 * в wp-config.php (перекрывают опцию).
 *
 * @package Inc\Modules\SmartCaptcha\Config
 */
class SmartCaptchaConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_smart_captcha';

	private const SITE_CONSTANT   = 'FS_LMS_CAPTCHA_SITE_KEY';
	private const SERVER_CONSTANT = 'FS_LMS_CAPTCHA_SERVER_KEY';

	private const DEFAULTS = array(
		'enabled'    => false,
		'site_key'   => '',
		'server_key' => '',
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

	/** Включён ли модуль в рантайме. Константа wp-config перекрывает тумблер. */
	public function isEnabled(): bool {
		if ( defined( 'FS_LMS_SMART_CAPTCHA' ) ) {
			return (bool) constant( 'FS_LMS_SMART_CAPTCHA' );
		}
		return (bool) ( $this->get()['enabled'] ?? false );
	}

	/** Публичный (клиентский) ключ. Константа wp-config перекрывает значение из опции. */
	public function siteKey(): string {
		if ( $this->siteKeyFromConstant() ) {
			return (string) constant( self::SITE_CONSTANT );
		}
		return (string) ( $this->get()['site_key'] ?? '' );
	}

	/** Секретный (серверный) ключ. Константа wp-config перекрывает значение из опции. */
	public function serverKey(): string {
		if ( $this->serverKeyFromConstant() ) {
			return (string) constant( self::SERVER_CONSTANT );
		}
		return (string) ( $this->get()['server_key'] ?? '' );
	}

	public function siteKeyFromConstant(): bool {
		return defined( self::SITE_CONSTANT ) && '' !== (string) constant( self::SITE_CONSTANT );
	}

	public function serverKeyFromConstant(): bool {
		return defined( self::SERVER_CONSTANT ) && '' !== (string) constant( self::SERVER_CONSTANT );
	}

	/**
	 * Разовая миграция из легаси core-конфига: если своя опция ещё не создана, а в
	 * `fs_lms_plugin_config` лежат ключи капчи — переносим их сюда и включаем модуль,
	 * чтобы существующие установки продолжили работать без ручных действий.
	 *
	 * @return void
	 */
	public function maybeMigrateFromCore(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return; // своя опция уже есть — миграция не нужна
		}

		$legacy = get_option( 'fs_lms_plugin_config', array() );
		if ( ! is_array( $legacy ) ) {
			return;
		}

		$site   = trim( (string) ( $legacy['captcha_site_key'] ?? '' ) );
		$server = trim( (string) ( $legacy['captcha_server_key'] ?? '' ) );

		if ( '' !== $site || '' !== $server ) {
			update_option( self::OPTION, array(
				'enabled'    => true,
				'site_key'   => $site,
				'server_key' => $server,
			), false );
		}
	}
}
