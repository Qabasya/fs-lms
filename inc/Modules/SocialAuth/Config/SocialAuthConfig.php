<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Config;

/**
 * Конфиг модуля SocialAuth (OAuth-вход через соцсети).
 * Модуль владеет СВОЕЙ опцией `fs_lms_social_auth` — ядро о ней не знает.
 *
 * Уровни выключения:
 *  1) тумблер на странице «Статистика» (опция `fs_lms_social_auth.enabled`);
 *  2) константа `FS_LMS_SOCIAL_AUTH` в wp-config.php (перекрывает тумблер);
 *  3) удаление каталога `inc/Modules/SocialAuth/` + строки `SocialAuthModule::class` в Init.
 *
 * По умолчанию модуль включён (backward-compat с монолитом — соц-вход исторически активен).
 */
class SocialAuthConfig {

	/** Ключ опции модуля (вне core OptionName — изоляция). */
	public const OPTION = 'fs_lms_social_auth';

	/** По умолчанию включён — в отличие от прочих модулей (монолит-совместимость). */
	private const DEFAULTS = array(
		'enabled' => true,
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
		if ( defined( 'FS_LMS_SOCIAL_AUTH' ) ) {
			return (bool) constant( 'FS_LMS_SOCIAL_AUTH' );
		}
		return (bool) ( $this->get()['enabled'] ?? true );
	}

	/**
	 * Разовая миграция из легаси core-конфига: переносит флаг
	 * `fs_lms_plugin_config['social_auth_enabled']` в свою опцию и удаляет легаси-ключ.
	 * Если ключа не было — сохраняем включённое состояние (старое поведение монолита).
	 *
	 * @return void
	 */
	public function maybeMigrateFromCore(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			return; // своя опция уже есть — миграция не нужна
		}

		$legacy  = get_option( 'fs_lms_plugin_config', array() );
		$legacy  = is_array( $legacy ) ? $legacy : array();
		// Старое поведение: ключ отсутствует => включено.
		$enabled = ! isset( $legacy['social_auth_enabled'] ) || ! empty( $legacy['social_auth_enabled'] );

		update_option( self::OPTION, array( 'enabled' => $enabled ), false );

		if ( array_key_exists( 'social_auth_enabled', $legacy ) ) {
			unset( $legacy['social_auth_enabled'] );
			update_option( 'fs_lms_plugin_config', $legacy, false );
		}
	}
}
