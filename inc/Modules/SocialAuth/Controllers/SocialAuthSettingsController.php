<?php

declare( strict_types=1 );

namespace Inc\Modules\SocialAuth\Controllers;

use Inc\Contracts\ServiceInterface;
use Inc\Modules\SocialAuth\Config\SocialAuthConfig;

/**
 * Контроллер настроек SocialAuth.
 *
 * Регистрирует всегда (даже при выключенном модуле), чтобы:
 * — WP Settings API мог сохранять опцию fs_lms_auth_settings;
 * — Dashboard знал о модуле через `fs_lms_dashboard_modules`;
 * — toggle-хук мог работать в обе стороны (вкл/выкл).
 *
 * Вкладка «Авторизация» в Настройках показывается только когда модуль включён.
 */
class SocialAuthSettingsController implements ServiceInterface {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_filter( 'fs_lms_settings_tabs', array( $this, 'addSettingsTab' ) );
		add_filter( 'fs_lms_dashboard_modules', array( $this, 'registerDashboardModule' ) );
		add_action( 'fs_lms_module_toggle_social_auth', array( $this, 'onToggle' ) );
	}

	public function registerSettings(): void {
		register_setting( 'fs_lms_auth_group', 'fs_lms_auth_settings' );
	}

	public function addSettingsTab( array $tabs ): array {
		// Вкладка скрыта когда модуль выключен
		if ( ! SocialAuthConfig::isEnabled() ) {
			return $tabs;
		}

		$auth_tab = array(
			'tab-2' => array(
				'title' => 'Авторизация',
				'path'  => FS_LMS_PATH . 'inc/Modules/SocialAuth/templates/settings-tab.php',
			),
		);

		// Вставляем после tab-1
		$result = array();
		foreach ( $tabs as $key => $tab ) {
			$result[ $key ] = $tab;
			if ( 'tab-1' === $key ) {
				$result = array_merge( $result, $auth_tab );
			}
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $modules
	 * @return array<int, array<string, mixed>>
	 */
	public function registerDashboardModule( array $modules ): array {
		$const_defined = defined( 'FS_LMS_SOCIAL_AUTH' );

		$modules[] = array(
			'id'           => 'social_auth',
			'title'        => 'Авторизация через соцсети',
			'description'  => 'OAuth-вход через Google, VK и GitHub. При отключении исчезает вкладка «Авторизация» в Настройках и прекращается регистрация OAuth-маршрутов.',
			'enabled'      => SocialAuthConfig::isEnabled(),
			'const_locked' => $const_defined,
			'const_key'    => 'FS_LMS_SOCIAL_AUTH',
		);

		return $modules;
	}

	public function onToggle( bool $enabled ): void {
		SocialAuthConfig::toggle( $enabled );
	}
}
