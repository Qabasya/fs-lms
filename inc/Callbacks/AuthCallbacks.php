<?php

namespace Inc\Callbacks;

use Inc\Core\BaseController;
use Inc\Enums\AuthProvider;
use Inc\Repositories\SettingsRepository;

class AuthCallbacks extends BaseController {

	public function __construct( private readonly SettingsRepository $settings_repo ) {
		parent::__construct();
	}
	// Временная функция
	public function renderAuthTestPage(): string {
		$output  = '<div class="lms-auth-test-wrapper" style="padding: 20px; border: 1px solid #ccc;">';
		$output .= '<h3>Тест авторизации</h3>';

		foreach ( AuthProvider::cases() as $provider ) {
			if ( ! $this->settings_repo->isProviderEnabled( $provider->value ) ) {
				continue;
			}

			$id        = $provider->configKey();
			$login_url = home_url( '/lms-auth/login?provider=' . $id );
			$output   .= sprintf(
				'<p><a href="%s" class="button auth-btn-%s" style="display:inline-block; padding:10px 20px; background:#0073aa; color:#fff; text-decoration:none; border-radius:4px; margin-bottom:5px;">Войти через %s</a></p>',
				esc_url( $login_url ),
				esc_attr( $id ),
				esc_html( $provider->label() )
			);
		}

		if ( is_user_logged_in() ) {
			$user    = wp_get_current_user();
			$output .= '<hr><p style="color: green;">Вы сейчас авторизованы как: <strong>' . esc_html( $user->display_name ) . '</strong></p>';
			$output .= '<p><a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '">Выйти</a></p>';
		}

		$output .= '</div>';

		return $output;
	}
}
