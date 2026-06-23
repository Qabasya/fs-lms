<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Config;

/**
 * Конфиг модуля SocialAuth.
 *
 * Уровни выключения:
 *  1) константа FS_LMS_SOCIAL_AUTH=false в wp-config.php (жёсткий оффлайн, перекрывает опцию);
 *  2) опция fs_lms_plugin_config['social_auth_enabled'] = false (тумблер в будущем UI);
 *  3) удаление каталога inc/Modules/SocialAuth/ + строки SocialAuthModule::class в Init.
 *
 * По умолчанию модуль включён (backward-compat с монолитом).
 */
class SocialAuthConfig {

	public static function isEnabled(): bool {
		if ( defined( 'FS_LMS_SOCIAL_AUTH' ) && false === FS_LMS_SOCIAL_AUTH ) {
			return false;
		}

		$config = get_option( 'fs_lms_plugin_config', array() );

		// По умолчанию true: если ключ не задан, модуль включён
		return ! isset( $config['social_auth_enabled'] ) || ! empty( $config['social_auth_enabled'] );
	}

	public static function toggle( bool $enabled ): void {
		$config                     = get_option( 'fs_lms_plugin_config', array() );
		$config['social_auth_enabled'] = $enabled;
		update_option( 'fs_lms_plugin_config', $config );
	}
}
